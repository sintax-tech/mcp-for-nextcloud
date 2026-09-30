<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use InvalidArgumentException;

/** Validates tool arguments against the small JSON Schema subset the tools declare, applying defaults. */
final class ArgumentValidator {
    /**
     * @param array<string, mixed> $schema object schema with properties/required/additionalProperties=false
     * @param array<string, mixed> $arguments arguments sent by the client
     * @return array<string, mixed> validated arguments with defaults applied
     * @throws InvalidArgumentException naming the first unknown, missing or invalid argument
     */
    public static function validate(array $schema, array $arguments): array {
        $properties = $schema['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array)$properties : $properties;
        if ($arguments !== [] && array_is_list($arguments)) {
            throw new InvalidArgumentException('Invalid arguments');
        }
        foreach (array_keys($arguments) as $key) {
            if (!isset($properties[$key])) {
                throw new InvalidArgumentException("Unknown argument: $key");
            }
        }
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $arguments)) {
                throw new InvalidArgumentException("Missing argument: $key");
            }
        }
        $out = [];
        foreach ($properties as $key => $rule) {
            if (!array_key_exists($key, $arguments)) {
                if (array_key_exists('default', $rule)) {
                    $out[$key] = $rule['default'];
                }
                continue;
            }
            $out[$key] = self::check((string)$key, $rule, $arguments[$key]);
        }
        return $out;
    }

    /**
     * A nested item is reported under its parent's key, not its own path: the protocol only forwards
     * "Invalid argument: <name>" style messages, so a path with brackets would be flattened to a generic
     * answer and the agent would lose which argument to fix.
     *
     * @param array<string, mixed> $rule property schema (type as string or list of types, minLength, maxLength, minimum, maximum, const, items, properties)
     * @throws InvalidArgumentException when the value breaks the rule
     */
    private static function check(string $key, array $rule, mixed $value): mixed {
        $types = is_array($rule['type'] ?? null) ? $rule['type'] : [$rule['type'] ?? null];
        foreach ($types as $type) {
            if (self::matches($type, $rule, $value)) {
                if (array_key_exists('const', $rule) && $value !== $rule['const']) {
                    break;
                }
                return is_array($value) ? self::nested($key, $rule, $value) : $value;
            }
        }
        throw new InvalidArgumentException("Invalid argument: $key");
    }

    /**
     * Validates the contents of an array or object property: every item against `items`, and the named
     * properties of an object against `properties`, honouring `required` and `additionalProperties`.
     *
     * @param string $key the argument name to report in a failure
     * @param array<string, mixed> $rule schema of the array or object
     * @param array<array-key, mixed> $value the value that already passed the type check
     * @return array<array-key, mixed> the same value, rebuilt from what was checked
     * @throws InvalidArgumentException when an item or property breaks its rule
     */
    private static function nested(string $key, array $rule, array $value): array {
        if (isset($rule['items']) && array_is_list($value)) {
            $out = [];
            foreach ($value as $index => $item) {
                $out[$index] = self::check($key, $rule['items'], $item);
            }
            return $out;
        }
        if (isset($rule['properties']) && !array_is_list($value)) {
            $properties = $rule['properties'];
            if (($rule['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $name) {
                    if (!isset($properties[$name])) {
                        throw new InvalidArgumentException("Invalid argument: $key");
                    }
                }
            }
            foreach ($properties as $name => $property) {
                if (!array_key_exists($name, $value)) {
                    if (in_array($name, $rule['required'] ?? [], true)) {
                        throw new InvalidArgumentException("Missing argument: $key");
                    }
                    if (array_key_exists('default', $property)) {
                        $value[$name] = $property['default'];
                    }
                    continue;
                }
                $value[$name] = self::check($key, $property, $value[$name]);
            }
            foreach ($rule['required'] ?? [] as $name) {
                if (!array_key_exists($name, $value)) {
                    throw new InvalidArgumentException("Missing argument: $key");
                }
            }
        }
        return $value;
    }

    /**
     * @param mixed $type one JSON Schema type name
     * @param array<string, mixed> $rule property schema with the type's constraints
     * @param mixed $value argument value
     * @return bool whether the value is of that type and within its constraints
     */
    private static function matches(mixed $type, array $rule, mixed $value): bool {
        return match ($type) {
            'string' => is_string($value)
                && mb_strlen($value) >= ($rule['minLength'] ?? 0)
                && (!isset($rule['maxLength']) || mb_strlen($value) <= $rule['maxLength']),
            'integer' => is_int($value)
                && (!isset($rule['minimum']) || $value >= $rule['minimum'])
                && (!isset($rule['maximum']) || $value <= $rule['maximum']),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value)
                && (!isset($rule['minItems']) || count($value) >= $rule['minItems'])
                && (!isset($rule['maxItems']) || count($value) <= $rule['maxItems']),
            'object' => is_array($value) && !array_is_list($value),
            'null' => $value === null,
            default => false,
        };
    }
}
