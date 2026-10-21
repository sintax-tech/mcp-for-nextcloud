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
final class DeleteNote implements WriteTool
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Deletion\NoteDeleter $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(name: 'notes_delete', module: 'notes', operation: 'delete', description: 'Deletes a note to the Nextcloud trash bin, where it can be restored.', inputSchema: array('type' => 'object', 'properties' => array('id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Id of the note.'), 'etag' => array('type' => 'string', 'description' => 'ETag read before; when it differs, nothing is changed.'), 'confirm_shared' => array('type' => 'boolean', 'description' => 'Required only when the note is outside your personal folder (shared, team folder, or external storage). Send only after confirming with the user.')), 'additionalProperties' => false, 'required' => array(0 => 'id')), app: 'notes');
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
