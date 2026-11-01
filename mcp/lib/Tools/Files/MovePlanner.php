<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** A validated plan, plus the confirmation a shared item needs when the caller has not given it. */
final class MovePlan {
	/**
	 * @param array<string, mixed> $plan the plan as the client sees it
	 * @param list<array{from:string, to:string}> $planned every item that passed the checks, in the order of the batch,
	 *   the shared ones included: they run once the caller has the shared confirmation
	 * @param array<string, mixed>|null $confirmation the guard payload for the first non-personal item
	 */
	public function __construct(
		public readonly array $plan,
		public readonly array $planned,
		public readonly ?array $confirmation,
	) {}

	/** @return bool whether every item may run */
	public function isOk(): bool {
		return (bool)$this->plan['ok'];
	}
}

/**
 * Reads a batch and says what would happen, without touching anything.
 *
 * This is the plan the user approves before the batch runs, and it is rebuilt again when the batch does run:
 * a blocked item does not stop it, because the agent has to see the whole list to be able to fix it, and a
 * plan of 200 items where 1 conflicts is a question for the user, not a failure to hide behind. The same
 * checks a single move runs come from Reorganization::inspect(), so a plan can never approve a move the
 * tool would refuse.
 */
final class MovePlanner {
	public function __construct(
		private Reorganization $reorganization,
		private NodeAccessInfo $access,
		private SharedWriteGuard $guard,
		private ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {}

	/**
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param list<array{from:string, to:string}> $moves requested moves
	 * @param list<string> $mkdirs folders the batch would create
	 * @return MovePlan the plan, the runnable items and the pending confirmation
	 * @throws \InvalidArgumentException for a malformed folder path
	 */
	public function plan(Folder $root, string $userId, array $moves, array $mkdirs): MovePlan {
		return (new MoveBatchPlanner($this->reorganization->copyMoveContext(), $this, $this->guard))->plan($root, $userId, $moves, $mkdirs);
	}

	/**
	 * What the batch would do about the folders it was asked to create. A path that is already there is
	 * reported as such and not counted as a creation: the undo only removes what the batch really made.
	 * A folder the run would refuse to create carries the reason, and the plan is not ok: a batch never
	 * starts by making half of its folders.
	 *
	 * @param Folder $root the user's folder
	 * @param list<string> $paths requested folders
	 * @return list<array{path:string, exists:bool, willCreate:bool, reason?:string}>
	 */
	public function planDirs(Folder $root, array $paths): array {
		$dirs = [];
		foreach ($paths as $path) {
			try {
				$this->reorganization->checkFolder($root, $path);
			} catch (ToolFailure $e) {
				$dirs[] = ['path' => $path, 'exists' => false, 'willCreate' => false, 'reason' => $e->getMessage()];
				continue;
			}
			$relative = ltrim(\OCA\Mcp\Platform\FileAccess\PathGuard::normalize($path), '/');
			$exists = false;
			if ($relative === '') {
				$exists = true;
			} elseif ($root->nodeExists($relative)) {
				$node = null;
				try {
					$node = $root->get($relative);
				} catch (\Throwable) {}
				if ($node !== null && ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node))) {
					$exists = true;
				}
			}
			$dirs[] = ['path' => $path, 'exists' => $exists, 'willCreate' => !$exists];
		}
		return $dirs;
	}
}
