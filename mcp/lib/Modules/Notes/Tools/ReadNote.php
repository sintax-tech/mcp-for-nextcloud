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
final class ReadNote implements Tool
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Reading\NoteReader $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name: 'notes_read', module: 'notes', operation: 'read', description: 'Reads the content of a note by id.', inputSchema: array('type' => 'object', 'properties' => array('id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Id of the note.')), 'additionalProperties' => false, 'required' => array(0 => 'id')), app: 'notes');
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return NodeAccess::run(fn(): array => ToolResult::json($this->operation->read($context->userId, $arguments['id'])));
    }
}
