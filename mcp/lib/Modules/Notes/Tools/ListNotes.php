<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
final class ListNotes implements Tool
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Reading\NoteLister $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name: 'notes_list', module: 'notes', operation: 'read', description: 'Lists the notes (Notes app) of the user.', inputSchema: array('type' => 'object', 'properties' => (object) array(), 'additionalProperties' => false), app: 'notes');
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return NodeAccess::run(fn(): array => ToolResult::json($this->operation->list($context->userId)));
    }
}
