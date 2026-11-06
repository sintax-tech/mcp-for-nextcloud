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
use OCA\Mcp\Modules\Files\Reorganization;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\BatchStore;

/** Preserves the existing Files UndoBatchAction behavior. */
final class UndoBatchAction {
	/**
	 * @param FilesCallContext $context existing context collaborator
	 * @param Reorganization $reorganization existing reorganization collaborator
	 * @param BatchStore $batches existing batches collaborator
	 * @param ITimeFactory $time existing time collaborator
	 */
	public function __construct(
		private FilesCallContext $context,
		private Reorganization $reorganization,
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
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->reorganization->undoBatch(
				$root,
				$userId,
				$arguments['batch_id'],
				$this->batches,
				$this->time->getTime(),
			)));
	}
}
