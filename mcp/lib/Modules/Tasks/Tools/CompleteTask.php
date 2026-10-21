<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class CompleteTask implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Tasks\Completion\TaskCompleter $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'tasks_complete_task',
            module: 'tasks',
            operation: 'edit',
            description: 'Mark a simple task completed with 100 percent progress and a completion timestamp. Returns a detailed plan without writing. Show it to the user, wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. Shared collections also require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('VTODO calendar path from List task calendars.'),
                    'uid' => DavCollectionSchema::uid(),
                    'etag' => DavCollectionSchema::etag(),
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
