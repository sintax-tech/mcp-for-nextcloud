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
use OCA\Mcp\Modules\Files\Creation\CreationMessages;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class UploadFile implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private FileCreation $creation,
    ) {}

    public static function definition(): ToolDefinition {
        $confirmShared = ['type' => 'boolean', 'description' => EditingMessages::confirmShared()];
        return new ToolDefinition(
            name: 'files_upload',
            module: 'files',
            operation: 'create',
            description: CreationMessages::uploadTool(),
            inputSchema: self::schema([
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => CreationMessages::newFilePath()],
                    'size' => ['type' => 'integer', 'minimum' => 0, 'description' => CreationMessages::uploadSize()],
                    'confirm_shared' => $confirmShared,
                ], ['path']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->creation->upload($root, $userId, $arguments['path'], $arguments['size'] ?? null, (bool)($arguments['confirm_shared'] ?? false))));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->creation->planUpload($root, $userId, $arguments['path'], $arguments['size'] ?? null));
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        return $required === [] ? $schema : $schema + ['required' => $required];
    }
}
