<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Delegate the native early and sink-time lock barriers. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCP\Files\Node;

/** Preserves the existing MoveLocks operations and refusal order. */
final class MoveLocks {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param ?LockAwareWrite $locks locks collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly ?LockAwareWrite $locks = null,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Refuses to move a node that files_lock locks against the user: open in Text or Office, locked by another person
	 * or a WebDAV client. A folder is refused when anything the user can reach inside it is, or is too large to check.
	 *
	 * @param Node $node the node about to move
	 * @param string $userId authenticated user
	 * @throws \OCA\Mcp\Platform\FileAccess\LockWriteFailure when a lock stops the move
	 * @return void
	 */
	public function assertUnlocked(Node $node, string $userId): void {
		$this->locks?->assertWritable($node, $userId);
	}

	/**
	 * Runs a move: checks the locks of the node, and of everything inside a folder, right before it, and explains a
	 * lock refusal of the storage.
	 *
	 * @param callable():Node $write the move
	 * @param Node $node the node it moves
	 * @param string $userId authenticated user
	 * @return Node what the write returned
	 */
	public function locked(callable $write, Node $node, string $userId): Node {
		return NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->run($write, $node, $userId), $this->visibilityGuard);
	}
}
