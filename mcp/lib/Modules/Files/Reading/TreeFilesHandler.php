<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Reorganization;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Preserves the existing Files TreeFilesHandler behavior. */
final class TreeFilesHandler {
	/**
	 * @param FilesCallContext $context existing context collaborator
	 * @param Reorganization $reorganization existing reorganization collaborator
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
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->reorganization->tree($root, $userId, $arguments['path'], $arguments['depth'], $arguments['limit'])));
	}
}
