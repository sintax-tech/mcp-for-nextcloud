<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Tools;

final class ListCalendars implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Tasks\Reading\TaskCalendarReader $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'tasks_list_calendars',
            module: 'tasks',
            operation: 'read',
            description: 'List your own and shared calendars supporting tasks (VTODO).',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [],
                'additionalProperties' => false,
            ],
            app: 'dav'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->execute($arguments, $context->userId);
    }
}
