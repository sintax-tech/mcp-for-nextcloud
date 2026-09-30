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
     * @param array<string, mixed> $rule property schema (type, minLength, maxLength, minimum, maximum, const)
     * @throws InvalidArgumentException when the value breaks the rule
     */
    private static function check(string $key, array $rule, mixed $value): mixed {
        $valid = match ($rule['type'] ?? null) {
            'string' => is_string($value)
                && mb_strlen($value) >= ($rule['minLength'] ?? 0)
                && (!isset($rule['maxLength']) || mb_strlen($value) <= $rule['maxLength']),
            'integer' => is_int($value)
                && (!isset($rule['minimum']) || $value >= $rule['minimum'])
                && (!isset($rule['maximum']) || $value <= $rule['maximum']),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid || (array_key_exists('const', $rule) && $value !== $rule['const'])) {
            throw new InvalidArgumentException("Invalid argument: $key");
        }
        return $value;
    }
}
