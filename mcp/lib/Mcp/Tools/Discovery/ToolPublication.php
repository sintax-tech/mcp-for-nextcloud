<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use OCA\Mcp\Mcp\Confirmation\WriteGate;

/** Projects a definition into the ordered client-facing publication contract. */
final class ToolPublication {
    /**
     * The definition as the client sees it: {@see WriteGate::publish()} runs first, so tools/list and the
     * guide both describe the `confirm` argument of every write.
     *
     * @param array<string, mixed> $definition a tool definition of a module, or of the guide itself
     * @return array{name:string, title:string, description:string, inputSchema:array<string, mixed>, annotations:array<string, bool|string>, module:string, operation:string, app?:string, grantAnyOf?:list<string>}
     */
    public static function present(array $definition): array {
        $definition = WriteGate::publish($definition);
        return [
            'name' => $definition['name'],
            'title' => ToolPresentation::title($definition['name']),
            'description' => $definition['description'],
            'inputSchema' => $definition['inputSchema'],
            'annotations' => ToolPresentation::annotations($definition['name'], $definition['operation'], ($definition['destructiveHint'] ?? false) === true),
            'module' => $definition['module'],
            'operation' => $definition['operation'],
        ] + (isset($definition['app']) ? ['app' => $definition['app']] : [])
            + (isset($definition['grantAnyOf']) ? ['grantAnyOf' => $definition['grantAnyOf']] : []);
    }
}
