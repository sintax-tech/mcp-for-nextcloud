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
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Reorganization\CopyMove;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class CopyFile implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private CopyMove $operation,
    ) {}

    public static function definition(): ToolDefinition {
        $etag = ['type' => 'string', 'description' => EditingMessages::etag()];
        $confirmShared = ['type' => 'boolean', 'description' => EditingMessages::confirmShared()];
        return new ToolDefinition(
            name: 'files_copy',
            module: 'files',
            operation: 'create',
            description: ReorganizationMessages::copyTool(),
            inputSchema: self::schema([
                    'from' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()],
                    'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path, e.g. /Archived/plan.md'],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['from', 'to']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->operation->copy($root, $userId, $arguments['from'], $arguments['to'], $arguments['etag'] ?? null, (bool)($arguments['confirm_shared'] ?? false))));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->operation->planCopy($root, $userId, $arguments['from'], $arguments['to'], $arguments['etag'] ?? null));
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
