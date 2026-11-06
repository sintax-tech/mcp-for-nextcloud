<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Reorganization;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Runs the operation that copies a node through reorganization guards inside the actor view. */
final class CopyFileHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param Reorganization $reorganization guarded folder, copy and move operations
	 */
	public function __construct(
		private FilesCallContext $context,
		private Reorganization $reorganization,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->reorganization->copy($root, $userId, $arguments['from'], $arguments['to'], $arguments['etag'] ?? null, $confirmed)));
	}
}
