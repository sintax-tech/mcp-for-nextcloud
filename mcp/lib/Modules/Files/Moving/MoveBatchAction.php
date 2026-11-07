<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Moving;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Reorganization\Reorganization;
use OCA\Mcp\Modules\Files\Reorganization\MovePlanner;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;

/** Runs the operation that executes a planned batch move inside the actor view. */
final class MoveBatchAction {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param Reorganization $reorganization guarded folder, copy and move operations
	 * @param MovePlanner $planner batch move planning service
	 * @param BatchStore $batches batch move and undo persistence
	 * @param ITimeFactory $time clock for batch timestamps
	 */
	public function __construct(
		private FilesCallContext $context,
		private Reorganization $reorganization,
		private MovePlanner $planner,
		private BatchStore $batches,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->reorganization->batch(
				$root,
				$userId,
				$arguments['moves'],
				$arguments['mkdirs'] ?? [],
				$confirmed,
				$this->planner,
				$this->batches,
				$this->time->getTime(),
			)));
	}
}
