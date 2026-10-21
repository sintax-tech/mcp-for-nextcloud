<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use stdClass;

class DeckSchema {
    /**
     * Schema of a tool that takes no arguments.
     *
     * @return array<string, mixed> Object schema with no properties.
     */
    public static function emptySchema(): array {
        return ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false];
    }

    /**
     * Builds an object schema that rejects unknown properties.
     *
     * A tool whose arguments are all optional leaves `required` out entirely: the MCP contract test
     * refuses an empty array there, and "absent" is the correct way to say "nothing is demanded".
     *
     * @param array<string, array<string, mixed>> $properties Declared properties.
     * @param list<string> $required Property names that must be present.
     * @return array<string, mixed> JSON Schema for the tool arguments.
     */
    public static function schema(array $properties, array $required): array {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * @param string $description Parameter description.
     * @param int|null $minimum Inclusive lower bound.
     * @param int|null $maximum Inclusive upper bound.
     * @param int|null $default Value applied by the registry when the argument is absent.
     * @return array<string, mixed> Integer property definition.
     */
    public static function integer(
        string $description,
        ?int $minimum = null,
        ?int $maximum = null,
        ?int $default = null,
    ): array {
        $property = ['type' => 'integer', 'description' => $description];
        if ($minimum !== null) {
            $property['minimum'] = $minimum;
        }
        if ($maximum !== null) {
            $property['maximum'] = $maximum;
        }
        if ($default !== null) {
            $property['default'] = $default;
        }
        return $property;
    }

    /**
     * @param string $description Parameter description.
     * @return array<string, mixed> Array of at most 100 account IDs.
     */
    public static function accountList(string $description): array {
        return [
            'type' => 'array',
            'maxItems' => 100,
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'description' => $description,
        ];
    }

    /**
     * @return array<string, mixed> Optional shared-resource confirmation property of a write tool.
     */
    public static function confirmShared(): array {
        return ['type' => 'boolean', 'description' => ToolMessages::PARAM_CONFIRM_SHARED];
    }

    /**
     * @param string $description Parameter description.
     * @param int $minimum Inclusive minimum length.
     * @param int|null $maximum Inclusive maximum length.
     * @param string|null $default Value applied by the registry when the argument is absent.
     * @return array<string, mixed> String property definition.
     */
    public static function string(
        string $description,
        int $minimum,
        ?int $maximum = null,
        ?string $default = null,
    ): array {
        $property = ['type' => 'string', 'description' => $description, 'minLength' => $minimum];
        if ($maximum !== null) {
            $property['maxLength'] = $maximum;
        }
        if ($default !== null) {
            $property['default'] = $default;
        }
        return $property;
    }

    /**
     * Builds a string property that also accepts null, used by the optional dates.
     *
     * @param string $description Parameter description.
     * @param string|null $default Value applied by the registry when the argument is absent, or null for none.
     * @return array<string, mixed> Property definition accepting a string or null.
     */
    public static function nullableString(string $description, ?string $default = null): array {
        $property = ['type' => ['string', 'null'], 'description' => $description];
        if ($default !== null) {
            $property['default'] = $default;
        }
        return $property;
    }

    public static function definitionArray(ToolDefinition $definition): array {
        return [
            'name' => $definition->name,
            'description' => $definition->description,
            'inputSchema' => $definition->inputSchema,
            'module' => $definition->module,
            'operation' => $definition->operation,
            'app' => $definition->app,
        ];
    }
}
