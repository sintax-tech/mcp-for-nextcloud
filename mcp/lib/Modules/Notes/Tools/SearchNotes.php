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
final class SearchNotes implements Tool
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Search\NoteSearch $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name: 'notes_search', module: 'notes', operation: 'read', description: 'Searches notes by title and content (Markdown).', inputSchema: array('type' => 'object', 'properties' => array('query' => array('type' => 'string', 'minLength' => 1, 'description' => 'Search term to find in note title or content.'), 'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Maximum number of notes to return (1-100, default 20).'), 'category' => array('type' => 'string', 'default' => '', 'description' => 'Optional category (subfolder) to limit search to; empty for all notes.')), 'additionalProperties' => false, 'required' => array(0 => 'query')), app: 'notes');
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return NodeAccess::run(fn(): array => ToolResult::json($this->operation->search($context->userId, $arguments['query'], (int) $arguments['limit'], (string) ($arguments['category'] ?? ''))));
    }
}
