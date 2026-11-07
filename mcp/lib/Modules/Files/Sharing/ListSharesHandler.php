<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Runs the operation that lists shares owned by the actor inside the actor view. */
final class ListSharesHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ?ShareLister $shareLister optional owner-share listing service
	 */
	public function __construct(
		private FilesCallContext $context,
		private ?ShareLister $shareLister = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->listShares($root, $userId, $arguments['path'] ?? null, (int)$arguments['offset'])));
	}

	/**
	 * files_list_shares: the shares of one own node, or one page of every share the user created.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string|null $path node whose shares to list, null for every share of the user
	 * @param int $offset shares to skip when listing without path
	 * @return array<string, mixed> {@see ShareLister::forPath()} or {@see ShareLister::mine()}
	 * @throws ToolFailure for a node that is missing, hidden, the root or not the user's own
	 */
	private function listShares(Folder $root, string $userId, ?string $path, int $offset): array {
		$lister = $this->shareLister ?? throw new \LogicException('ShareLister is not wired');
		return $path === null ? $lister->mine($root, $userId, $offset) : $lister->forPath($root, $userId, $path);
	}
}
