<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_move and delegates to the component that moves a node through reorganization guards. */
final class MoveFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Moving\MoveFileHandler $handler executor that moves a node through reorganization guards
	 * @param \OCA\Mcp\Modules\Files\Moving\MoveFilePreview $plans read-only planner for this write operation
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Moving\MoveFileHandler $handler, private \OCA\Mcp\Modules\Files\Moving\MoveFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$etag = ['type' => 'string', 'description' => FilesMessages::etag()];
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_move', 'module' => 'files', 'operation' => 'move',
				'description' => \OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::moveTool(),
				'inputSchema' => FilesSchema::schema([
					'from' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
					'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path, e.g. /2026/plan.md'],
					'etag' => $etag,
					'confirm_shared' => $confirmShared,
				], ['from', 'to'])]);
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
