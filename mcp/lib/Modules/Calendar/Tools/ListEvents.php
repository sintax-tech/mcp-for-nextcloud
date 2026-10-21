<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class ListEvents implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Calendar\Reading\EventReader $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'calendar_list_events',
            module: 'calendar',
            operation: 'read',
            description: 'Lists events in a time range (default: next 7 days).',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('calendar path (optional)'),
                    'from' => DavCollectionSchema::text('start ISO date', 10, 40),
                    'to' => DavCollectionSchema::text('end ISO date', 10, 40),
                ],
                'additionalProperties' => false,
            ],
            app: 'calendar'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->execute($arguments, $context->userId);
    }
}
