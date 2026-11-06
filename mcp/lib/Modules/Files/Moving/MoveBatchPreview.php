<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Moving;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Reorganization\Reorganization;
use OCA\Mcp\Modules\Files\Reorganization\MovePlanner;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Plans the operation that executes a planned batch move, without executing the write. */
final class MoveBatchPreview {
	/**
	 * @param FilesPreviewContext $context actor-root admission and node-access mapping
	 * @param Reorganization $reorganization guarded folder, copy and move operations
	 * @param MovePlanner $planner batch move planning service
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private Reorganization $reorganization,
		private MovePlanner $planner,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => $this->reorganization->planBatch($root, $userId, $arguments['moves'], $arguments['mkdirs'] ?? [], $this->planner));
	}
}
