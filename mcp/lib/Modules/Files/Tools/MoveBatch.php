<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_move_batch and delegates to the component that executes a planned batch move. */
final class MoveBatch implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Moving\MoveBatchAction $handler executor that executes a planned batch move
	 * @param \OCA\Mcp\Modules\Files\Moving\MoveBatchPreview $plans read-only planner for this write operation
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Moving\MoveBatchAction $handler, private \OCA\Mcp\Modules\Files\Moving\MoveBatchPreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_move_batch', 'module' => 'files', 'operation' => 'move',
				'description' => \OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::batchTool(),
				'inputSchema' => FilesSchema::schema([
					'moves' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ReorganizationLimits::BATCH_ITEMS,
						'description' => 'Moves in the order they will be executed.',
						'items' => ['type' => 'object',
							'properties' => [
								'from' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
								'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path.'],
							],
							'required' => ['from', 'to']]],
					'mkdirs' => ['type' => 'array', 'maxItems' => ReorganizationLimits::BATCH_ITEMS,
						'items' => ['type' => 'string', 'minLength' => 1],
						'description' => 'Folders to create before moves.'],
					'confirm_shared' => $confirmShared,
				], ['moves'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
