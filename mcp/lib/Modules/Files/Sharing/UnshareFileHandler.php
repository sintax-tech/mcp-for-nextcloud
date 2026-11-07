<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Runs the operation that removes an actor-owned share inside the actor view. */
final class UnshareFileHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ?ShareRemover $shareRemover optional owner-share removal service
	 */
	public function __construct(
		private FilesCallContext $context,
		private ?ShareRemover $shareRemover = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json(($this->shareRemover ?? throw new \LogicException('ShareRemover is not wired'))->apply($root, $userId, $arguments)));
	}
}
