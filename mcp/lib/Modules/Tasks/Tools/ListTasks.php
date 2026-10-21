<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class ListTasks implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Tasks\Reading\TaskLister $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'tasks_list_tasks',
            module: 'tasks',
            operation: 'read',
            description: 'List tasks, including undated tasks, in one visible task calendar, optionally only those with a category.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('VTODO calendar path from List task calendars.'),
                    'include_completed' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Include completed tasks.',
                    ],
                    'category' => DavCollectionSchema::text('Only list tasks with this category, compared exactly but ignoring case.', 1, 255),
                    'limit' => DavCollectionSchema::limit(),
                    'offset' => DavCollectionSchema::offset(),
                ],
                'additionalProperties' => false,
                'required' => ['calendar'],
            ],
            app: 'dav'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->execute($arguments, $context->userId);
    }
}
