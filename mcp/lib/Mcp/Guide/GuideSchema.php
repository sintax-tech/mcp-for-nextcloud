<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Guide;

/** Renders schema parameter types and constraints for the tool guide. */
final class GuideSchema {
    /**
     * @param array<string, mixed> $schema inputSchema of a tool
     * @return list<array{name:string, type:string, required:bool, description:string, constraints:list<string>}>
     */
    public static function parameters(array $schema): array {
        $properties = $schema['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array)$properties : (array)$properties;
        $required = array_map(strval(...), (array)($schema['required'] ?? []));
        $parameters = [];
        foreach ($properties as $name => $rule) {
            $rule = is_array($rule) ? $rule : [];
            $parameters[] = [
                'name' => (string)$name,
                'type' => self::type($rule),
                'required' => in_array((string)$name, $required, true),
                'description' => (string)($rule['description'] ?? ''),
                'constraints' => self::constraints($rule),
            ];
        }
        return $parameters;
    }

    /** @param array{name:string, type:string, required:bool, description:string, constraints:list<string>} $parameter */
    public static function parameterLine(array $parameter): string {
        $between = [$parameter['type'], $parameter['required'] ? 'required' : 'optional'];
        if ($parameter['constraints'] !== []) {
            $between[] = implode(', ', $parameter['constraints']);
        }
        $line = sprintf('- `%s` (%s)', $parameter['name'], implode(', ', $between));
        return $parameter['description'] === '' ? $line : $line . ' — ' . $parameter['description'];
    }

    /**
     * @param array<string, mixed> $rule one property schema
     * @return string the type as the guide shows it, unfolding the item type of an array
     */
    private static function type(array $rule): string {
        $type = implode('|', array_map(strval(...), (array)($rule['type'] ?? 'any')));
        if ($type !== 'array' || !is_array($rule['items'] ?? null)) {
            return $type;
        }
        $item = self::type($rule['items']);
        $properties = $rule['items']['properties'] ?? null;
        if ($item !== 'object' || $properties === null) {
            return 'array of ' . $item;
        }
        $properties = $properties instanceof \stdClass ? (array)$properties : (array)$properties;
        return 'array of objects (' . implode(', ', array_map(strval(...), array_keys($properties))) . ')';
    }

    /**
     * @param array<string, mixed> $rule one property schema
     * @return list<string> the limits of the parameter, in the wording of the schema
     */
    private static function constraints(array $rule): array {
        $out = [];
        if (array_key_exists('default', $rule)) {
            $out[] = 'default ' . self::value($rule['default']);
        }
        if (array_key_exists('const', $rule)) {
            $out[] = 'must be ' . self::value($rule['const']);
        }
        if (isset($rule['enum']) && is_array($rule['enum'])) {
            $out[] = 'one of ' . implode(', ', array_map(self::value(...), $rule['enum']));
        }
        // A string is measured in characters and a number in its own unit, so the two are not read alike.
        $string = in_array($rule['type'] ?? null, ['string', 'array'], true);
        $min = $string ? ($rule['minLength'] ?? null) : ($rule['minimum'] ?? null);
        $max = $string ? ($rule['maxLength'] ?? null) : ($rule['maximum'] ?? null);
        if ($min !== null && $max !== null) {
            $out[] = ($string ? 'length ' : '') . $min . '..' . $max;
        } elseif ($min !== null) {
            $out[] = ($string ? 'length from ' : 'from ') . $min;
        } elseif ($max !== null) {
            $out[] = ($string ? 'length up to ' : 'up to ') . $max;
        }
        if (isset($rule['minItems'])) {
            $out[] = 'at least ' . $rule['minItems'] . ' items';
        }
        if (isset($rule['maxItems'])) {
            $out[] = 'at most ' . $rule['maxItems'] . ' items';
        }
        return $out;
    }

    /** @param mixed $value a default or a const from the schema */
    private static function value(mixed $value): string {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }
}
