<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class EditTask implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Tasks\Editing\TaskEditor $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'tasks_edit_task',
            module: 'tasks',
            operation: 'edit',
            description: 'Edit supplied fields of a simple task, preserving other properties. Returns a detailed plan without writing. Show it to the user, wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. Shared collections also require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('VTODO calendar path from List task calendars.'),
                    'uid' => DavCollectionSchema::uid(),
                    'etag' => DavCollectionSchema::etag(),
                    'summary' => DavCollectionSchema::text('Task title. Omit fields to preserve their existing values.', 1, 1024),
                    'description' => DavCollectionSchema::text('Task description.', 0, 65536),
                    'start' => [
                        'type' => [
                            'string',
                            'null',
                        ],
                        'description' => 'ISO date or timestamp with offset; null clears the start.',
                    ],
                    'due' => [
                        'type' => [
                            'string',
                            'null',
                        ],
                        'description' => 'ISO date or timestamp with offset; null clears the due date. Must use the same date type as start.',
                    ],
                    'priority' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 9,
                        'description' => 'iCalendar priority: 0 undefined, 1 highest, 9 lowest.',
                    ],
                    'percentComplete' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 100,
                        'description' => 'Progress percentage. 100 completes the task; a lower value reopens it.',
                    ],
                    'categories' => [
                        'type' => 'array',
                        'maxItems' => 50,
                        'items' => [
                            'type' => 'string',
                            'maxLength' => 1024,
                        ],
                        'description' => 'Free-text task categories, as in the Tasks app: 1-255 characters each after trimming spaces. The list replaces all existing categories; [] clears them; omit to preserve them.',
                    ],
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'Acknowledge changing a collection owned by another person after showing the shared notice.',
                    ],
                ],
                'additionalProperties' => false,
                'required' => [
                    'calendar',
                    'uid',
                ],
            ],
            app: 'dav'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->execute($arguments, $context->userId);
    }
    public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->preview($arguments, $context->userId);
    }
}
