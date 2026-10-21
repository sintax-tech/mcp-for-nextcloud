<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Editing\EditRequest;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Editing\TextFileEditor;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;

/** Invoked behind ToolRegistry and WriteGate, which own global confirmation. */
final class EditFile implements WriteTool {
    public function __construct(private TextFileEditor $editor) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_edit',
            module: 'files',
            operation: 'edit',
            description: EditingMessages::editTool(),
            inputSchema: [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()],
                'content' => ['type' => 'string', 'description' => EditingMessages::content()],
                'etag' => ['type' => 'string', 'description' => EditingMessages::etag()],
                'confirm_shared' => ['type' => 'boolean', 'description' => EditingMessages::confirmShared()],
            ],
            'additionalProperties' => false,
            'required' => ['path', 'content'],
        ],
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->editor->preview(EditRequest::fromArray($arguments), $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->editor->execute(EditRequest::fromArray($arguments), $context->userId);
    }
}
