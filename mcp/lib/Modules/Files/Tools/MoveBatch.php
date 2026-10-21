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
use OCA\Mcp\Modules\Files\Reorganization\BatchMover;
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;
use OCA\Mcp\Modules\Files\Reorganization\MovePlanner;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;

final class MoveBatch implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private BatchMover $operation,
        private BatchStore $batches,
        private ITimeFactory $time,
        private MovePlanner $planner,
    ) {}

    public static function definition(): ToolDefinition {
        $confirmShared = ['type' => 'boolean', 'description' => EditingMessages::confirmShared()];
        return new ToolDefinition(
            name: 'files_move_batch',
            module: 'files',
            operation: 'move',
            description: ReorganizationMessages::batchTool(),
            inputSchema: self::schema([
                    'moves' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ReorganizationLimits::BATCH_ITEMS,
                        'description' => 'Moves in the order they will be executed.',
                        'items' => ['type' => 'object',
                            'properties' => [
                                'from' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()],
                                'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path.'],
                            ],
                            'required' => ['from', 'to']]],
                    'mkdirs' => ['type' => 'array', 'maxItems' => ReorganizationLimits::BATCH_ITEMS,
                        'items' => ['type' => 'string', 'minLength' => 1],
                        'description' => 'Folders to create before moves.'],
                    'confirm_shared' => $confirmShared,
                ], ['moves']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->operation->batch($root, $userId, $arguments['moves'], $arguments['mkdirs'] ?? [], (bool)($arguments['confirm_shared'] ?? false), $this->planner, $this->batches, $this->time->getTime())));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->operation->planBatch($root, $userId, $arguments['moves'], $arguments['mkdirs'] ?? [], $this->planner));
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
