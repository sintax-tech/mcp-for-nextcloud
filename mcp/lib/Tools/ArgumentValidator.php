<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use InvalidArgumentException;

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
        return self::object($schema, $arguments, '');
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
     * @return array<string, mixed> validated object with defaults applied
     * @throws InvalidArgumentException naming the first unknown, missing or invalid argument
     */
    private static function object(array $schema, array $value, string $path): array {
        if ($value !== [] && array_is_list($value)) {
            // The root keeps its own wording: a list where the call object belongs is a malformed call, not a
            // malformed argument, and clients match on this message.
            throw new InvalidArgumentException($path === '' ? 'Invalid arguments' : 'Invalid argument: ' . $path);
        }

        $properties = $schema['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array)$properties : $properties;
        foreach (array_keys($value) as $key) {
            if (!isset($properties[$key])) {
                throw new InvalidArgumentException('Unknown argument: ' . self::key($path, (string)$key));
            }
        }
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException('Missing argument: ' . self::key($path, (string)$key));
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
            $out[$key] = self::check(self::key($path, $key), is_array($rule) ? $rule : [], $value[$key]);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $rule property schema (type as string or list of types, minLength, maxLength, minimum, maximum, const, enum, object properties, array items/minItems/maxItems)
     * @param mixed $value argument value
     * @throws InvalidArgumentException when the value breaks the rule
     */
    private static function check(string $key, array $rule, mixed $value): mixed {
        // Nesting recurses with the same rules as the root, so only a single type may name a structure.
        if (($rule['type'] ?? null) === 'object') {
            if (!is_array($value)) {
                throw new InvalidArgumentException("Invalid argument: $key");
            }
            return self::object($rule, $value, $key);
        }
        if (($rule['type'] ?? null) === 'array') {
            if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                throw new InvalidArgumentException("Invalid argument: $key");
            }
            $count = count($value);
            if ($count < ($rule['minItems'] ?? 0) || (isset($rule['maxItems']) && $count > $rule['maxItems'])) {
                throw new InvalidArgumentException("Invalid argument: $key");
            }
            // A schema that does not say what its items look like (a plain list of strings, or one whose
            // shape is checked further down) has nothing to validate each item against, and inventing an
            // empty rule would refuse every element it was given.
            if (!isset($rule['items']) || !is_array($rule['items'])) {
                return $value;
            }
            $out = [];
            foreach ($value as $index => $item) {
                $out[] = self::check($key . '[' . $index . ']', $rule['items'], $item);
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
        throw new InvalidArgumentException("Invalid argument: $key");
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

    /** Joins a parent path with a key, keeping the root messages exactly as they were. */
    private static function key(string $path, string $key): string {
        return $path === '' ? $key : $path . '.' . $key;
    }
}
