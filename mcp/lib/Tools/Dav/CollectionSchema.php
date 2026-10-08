<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Dav;

use OCA\Mcp\Tools\Calendar\ToolSchema;

/** Schema fragments shared by the personal DAV collection modules. */
final class CollectionSchema {
    /**
     * Builds a bounded text field using the existing calendar schema helper.
     *
     * @param string $description English field description
     * @param int $minimum minimum text length
     * @param int $maximum maximum text length
     * @return array<string, mixed>
     */
    public static function text(string $description, int $minimum = 0, int $maximum = 65536): array {
        return ToolSchema::text($description, $minimum, $maximum);
    }

    /**
     * Declares a DAV tool and adds the confirmation guidance required for collection writes.
     *
     * @param string $module grant module name
     * @param string $app required Nextcloud app ID
     * @param string $name tool name
     * @param string $description English tool description
     * @param string $operation read, create, edit or delete, deciding whether the call needs confirmation
     * @param array<string, mixed> $properties input schema fields
     * @param list<string> $required required input field names
     * @return array<string, mixed>
     */
    public static function definition(
        string $module,
        string $app,
        string $name,
        string $description,
        string $operation,
        array $properties,
        array $required = [],
    ): array {
        $definition = ToolSchema::definition($name, $description, $operation, $properties, $required);
        $definition['module'] = $module;
        $definition['app'] = $app;
        if ($operation !== 'read') {
            $definition['description'] .= ' Returns a detailed plan without writing. Show it to the user, '
                . 'wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. '
                . 'Shared collections also require confirm_shared=true.';
            $definition['inputSchema']['properties']->confirm_shared = [
                'type' => 'boolean',
                'description' => 'Acknowledge changing a collection owned by another person after showing the shared notice.',
            ];
        }
        return $definition;
    }

    /**
     * Defines the bounded continuation offset used by contact and task listings.
     *
     * @return array<string, mixed>
     */
    public static function offset(): array {
        return [
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 1000000,
            'default' => 0,
            'description' => 'Offset among matching results; use nextOffset to continue.',
        ];
    }

    /**
     * Defines the bounded page size used by contact and task listings.
     *
     * @return array<string, mixed>
     */
    public static function limit(): array {
        return [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 200,
            'default' => 50,
            'description' => 'Maximum results (1-200).',
        ];
    }
}
