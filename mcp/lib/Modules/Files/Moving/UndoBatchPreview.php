<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Moving;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Reorganization\Reorganization;
use OCA\Mcp\Modules\Files\FilesPreviewContext;
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;

/** Plans the operation that undoes a recorded batch move, without executing the write. */
final class UndoBatchPreview {
	/**
	 * @param FilesPreviewContext $context actor-root admission and node-access mapping
	 * @param Reorganization $reorganization guarded folder, copy and move operations
	 * @param BatchStore $batches batch move and undo persistence
	 * @param ITimeFactory $time clock for batch timestamps
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private Reorganization $reorganization,
		private BatchStore $batches,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => $this->reorganization->planUndoBatch($root, $userId, $arguments['batch_id'], $this->batches, $this->time->getTime()));
	}
}
