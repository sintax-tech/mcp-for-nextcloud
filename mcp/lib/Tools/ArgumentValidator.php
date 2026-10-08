<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;

/**
 * Validates tool arguments against the small JSON Schema subset the tools declare, applying defaults.
 *
 * The subset covers scalars (null, string, integer, boolean) plus, since the batch tools arrived, objects and
 * arrays of objects. Nesting is validated with the same rules as the root, so a nested object cannot smuggle in
 * an unknown key or skip its required fields, and errors name the exact path that failed instead of a vague "the
 * arguments were wrong". What is still deliberately absent is the rest of JSON Schema: no oneOf, no $ref, no
 * pattern, no union of shapes.
 */
final class ArgumentValidator {
    /**
     * @param array<string, mixed> $schema object schema with properties/required/additionalProperties=false
     * @param array<string, mixed> $arguments arguments sent by the client
     * @return array<string, mixed> validated arguments with defaults applied
     * @throws InvalidArgumentException naming the first unknown, missing or invalid argument
     */
    public static function validate(array $schema, array $arguments): array {
        return self::object($schema, $arguments, '', self::declared($schema));
    }

    /**
     * Every property name the schema declares at any level, so an unknown key can be told from a declared name in the wrong place.
     *
     * @param array<string, mixed> $schema object schema
     * @return array<string, true> declared names as keys
     */
    private static function declared(array $schema): array {
        $names = [];
        $properties = $schema['properties'] ?? [];
        foreach ($properties instanceof \stdClass ? (array)$properties : $properties as $key => $rule) {
            $names[(string)$key] = true;
            if (is_array($rule)) {
                $names += self::declared($rule);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $names += self::declared($schema['items']);
        }
        return $names;
    }

    /**
     * Validates one object-shaped value, which is either the whole call or a nested property or array element.
     *
     * An empty array is accepted as an empty object: that is what json_decode('{}') hands over, and refusing it
     * would make a nested object with only optional fields impossible to send.
     *
     * @param array<string, mixed> $schema object schema with properties/required
     * @param array<string, mixed> $value value shaped like an object
     * @param string $path dot path of this value for error messages, empty at the root
     * @param array<string, true> $declared names the whole schema declares, to tell an unknown key from a misplaced one
     * @return array<string, mixed> validated object with defaults applied
     * @throws InvalidArgumentException naming the first unknown, missing or invalid argument
     */
    private static function object(array $schema, array $value, string $path, array $declared): array {
        if ($value !== [] && array_is_list($value)) {
            // The root keeps its own wording: a list where the call object belongs is a malformed call, not a
            // malformed argument, and clients match on this message.
            throw new ArgumentValidationException($path === '' ? 'Invalid arguments' : 'Invalid argument: ' . $path, $path, self::rule(['type' => 'object']));
        }

        $properties = $schema['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array)$properties : $properties;
        foreach (array_keys($value) as $key) {
            if (!isset($properties[$key])) {
                // The field is the name only when the schema declares it somewhere: then it is a fixed string of
                // ours, never the client's. Any other name stays out of the answer and the parent is named instead.
                $field = isset($declared[(string)$key]) ? self::key($path, (string)$key) : $path;
                throw new ArgumentValidationException('Unknown argument: ' . self::key($path, (string)$key), $field, Translator::t('unknown property; use only declared arguments'));
            }
        }
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $value)) {
                throw new ArgumentValidationException('Missing argument: ' . self::key($path, (string)$key), self::key($path, (string)$key), Translator::t('required argument'));
            }
        }

        $out = [];
        foreach ($properties as $key => $rule) {
            $key = (string)$key;
            if (!array_key_exists($key, $value)) {
                if (is_array($rule) && array_key_exists('default', $rule)) {
                    $out[$key] = $rule['default'];
                }
                continue;
            }
            $out[$key] = self::check(self::key($path, $key), is_array($rule) ? $rule : [], $value[$key], $declared);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $rule property schema (type as string or list of types, minLength, maxLength, minimum, maximum, const, enum, object properties, array items/minItems/maxItems)
     * @param mixed $value argument value
     * @param array<string, true> $declared names the whole schema declares
     * @throws InvalidArgumentException when the value breaks the rule
     */
    private static function check(string $key, array $rule, mixed $value, array $declared): mixed {
        // Nesting recurses with the same rules as the root, so only a single type may name a structure.
        if (($rule['type'] ?? null) === 'object') {
            if (!is_array($value)) {
                throw new ArgumentValidationException("Invalid argument: $key", $key, self::rule($rule));
            }
            return self::object($rule, $value, $key, $declared);
        }
        if (($rule['type'] ?? null) === 'array') {
            if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                throw new ArgumentValidationException("Invalid argument: $key", $key, self::rule($rule));
            }
            $count = count($value);
            if ($count < ($rule['minItems'] ?? 0) || (isset($rule['maxItems']) && $count > $rule['maxItems'])) {
                throw new ArgumentValidationException("Invalid argument: $key", $key, self::rule($rule));
            }
            // A schema that does not say what its items look like (a plain list of strings, or one whose
            // shape is checked further down) has nothing to validate each item against, and inventing an
            // empty rule would refuse every element it was given.
            if (!isset($rule['items']) || !is_array($rule['items'])) {
                return $value;
            }
            $out = [];
            foreach ($value as $index => $item) {
                $out[] = self::check($key . '[' . $index . ']', $rule['items'], $item, $declared);
            }
            return $out;
        }

        $types = is_array($rule['type'] ?? null) ? $rule['type'] : [$rule['type'] ?? null];
        foreach ($types as $type) {
            if (self::matches($type, $rule, $value)) {
                if (array_key_exists('const', $rule) && $value !== $rule['const']) {
                    break;
                }
                if (isset($rule['enum']) && is_array($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                    break;
                }
                return $value;
            }
        }
        throw new ArgumentValidationException("Invalid argument: $key", $key, self::rule($rule));
    }

    /**
     * @param mixed $type one JSON Schema type name
     * @param array<string, mixed> $rule property schema with the type's constraints
     * @param mixed $value argument value
     * @return bool whether the value is of that type and within its constraints
     */
    private static function matches(mixed $type, array $rule, mixed $value): bool {
        return match ($type) {
            'null' => $value === null,
            'string' => is_string($value)
                && mb_strlen($value) >= ($rule['minLength'] ?? 0)
                && (!isset($rule['maxLength']) || mb_strlen($value) <= $rule['maxLength']),
            'integer' => is_int($value)
                && (!isset($rule['minimum']) || $value >= $rule['minimum'])
                && (!isset($rule['maximum']) || $value <= $rule['maximum']),
            'boolean' => is_bool($value),
            default => false,
        };
    }

    /**
     * Describes declared constraints without copying enum members or client data.
     * @param array<string, mixed> $rule Published schema constraints.
     * @return string Localized rule suitable for an error response.
     */
    private static function rule(array $rule): string {
        $type = $rule['type'] ?? [];
        $types = is_array($type) ? $type : [$type];
        $parts = [Translator::t('expected %s', [implode(' or ', $types)])];
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems'] as $bound) {
            if (isset($rule[$bound])) {
                $parts[] = match ($bound) {
                    'minimum' => Translator::t('minimum %s', [$rule[$bound]]),
                    'maximum' => Translator::t('maximum %s', [$rule[$bound]]),
                    'minLength' => Translator::t('minimum length %s', [$rule[$bound]]),
                    'maxLength' => Translator::t('maximum length %s', [$rule[$bound]]),
                    'minItems' => Translator::t('minimum items %s', [$rule[$bound]]),
                    'maxItems' => Translator::t('maximum items %s', [$rule[$bound]]),
                };
            }
        }
        if (isset($rule['enum'])) {
            $parts[] = Translator::t('must match a declared enum option');
        }
        if (array_key_exists('const', $rule)) {
            $parts[] = Translator::t('must match the declared constant');
        }
        return implode('; ', $parts);
    }

    /** Joins a parent path with a key, keeping the root messages exactly as they were. */
    private static function key(string $path, string $key): string {
        return $path === '' ? $key : $path . '.' . $key;
    }
}
