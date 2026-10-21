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
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;
use OCA\Mcp\Modules\Files\Reorganization\BatchUndo;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;

final class UndoBatch implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private BatchUndo $operation,
        private BatchStore $batches,
        private ITimeFactory $time,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_undo_batch',
            module: 'files',
            operation: 'move',
            description: ReorganizationMessages::undoTool(),
            inputSchema: self::schema([
                    'batch_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The batch_id returned by files_move_batch.'],
                ], ['batch_id']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->operation->undoBatch($root, $userId, $arguments['batch_id'], $this->batches, $this->time->getTime())));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->operation->planUndoBatch($root, $userId, $arguments['batch_id'], $this->batches, $this->time->getTime()));
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
