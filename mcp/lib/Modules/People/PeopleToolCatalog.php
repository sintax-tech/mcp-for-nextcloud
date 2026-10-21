<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\People\Tools\SearchUsers;

final class PeopleToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
        return [SearchUsers::class];
    }

    /**
     * @param list<class-string<Tool>>|null $classes explicit entries, defaulting to this feature's catalog
     * @return array<string, array<string, mixed>> original metadata shape in publication order
     */
    public static function definitions(?array $classes = null): array {
        $result = [];
        foreach ($classes ?? self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $definition = $class::definition();
            if ($definition->operation !== 'read') {
                throw new \LogicException('People tools must be read-only');
            }
            if (isset($result[$definition->name])) {
                throw new \LogicException('Duplicate tool: ' . $definition->name);
            }
            $result[$definition->name] = [
                'name' => $definition->name,
                'description' => $definition->description,
                'module' => $definition->module,
                'operation' => $definition->operation,
                'inputSchema' => $definition->inputSchema,
            ];
        }
        return $result;
    }
}
