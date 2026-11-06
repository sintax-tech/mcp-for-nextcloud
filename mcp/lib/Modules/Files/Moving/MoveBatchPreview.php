<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Moving;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Reorganization;
use OCA\Mcp\Modules\Files\MovePlanner;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Preserves the existing Files MoveBatchPreview behavior. */
final class MoveBatchPreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param Reorganization $reorganization existing reorganization collaborator
	 * @param MovePlanner $planner existing planner collaborator
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
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => $this->reorganization->planBatch($root, $userId, $arguments['moves'], $arguments['mkdirs'] ?? [], $this->planner));
	}
}
