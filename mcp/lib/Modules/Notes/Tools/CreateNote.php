<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
final class CreateNote implements WriteTool
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Creation\NoteCreator $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name: 'notes_create', module: 'notes', operation: 'create', description: 'Creates a note; never overwrites an existing one.', inputSchema: array('type' => 'object', 'properties' => array('title' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Title (file name).'), 'content' => array('type' => 'string', 'default' => '', 'description' => 'Content in Markdown.'), 'category' => array('type' => 'string', 'default' => '', 'description' => 'Category (subfolder); empty for the root.'), 'confirm_shared' => array('type' => 'boolean', 'description' => 'Required only when the note is outside your personal folder (shared, team folder, or external storage). Send only after confirming with the user.')), 'additionalProperties' => false, 'required' => array(0 => 'title')), app: 'notes');
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return NodeAccess::run(fn(): array => ToolResult::json($this->operation->execute($arguments, $context->userId)));
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return NodeAccess::run(fn(): array => $this->operation->preview($arguments, $context->userId));
    }
}
