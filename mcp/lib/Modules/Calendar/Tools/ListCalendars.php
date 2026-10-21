<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

final class ListCalendars implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Calendar\Reading\CalendarReader $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'calendar_list_calendars',
            module: 'calendar',
            operation: 'read',
            description: 'Lists calendars visible to the user.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [],
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
