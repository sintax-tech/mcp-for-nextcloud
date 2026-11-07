<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Inspect recorded undo destinations and conflicts before moving nodes back. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Preserves the existing UndoConflicts operations and refusal order. */
final class UndoConflicts {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param MoveLocks $moveLocks moveLocks collaborator.
	 * @param MoveDestination $moveDestination moveDestination collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly MoveLocks $moveLocks,
		private readonly MoveDestination $moveDestination,
	) {
	}

	/**
	 * Checks every move of a batch before the undo touches anything, in reverse order.
	 *
	 * @param Folder $root the user's folder
	 * @param Batch $batch the batch to check
	 * @param string $userId authenticated user
	 * @return list<array{from:string, to:string, reason:string}> the items that block the undo, empty when it may run
	 */
	public function undoConflicts(Folder $root, Batch $batch, string $userId): array {
		$conflicts = [];
		foreach (array_reverse($batch->moves) as $move) {
			try {
				$this->assertUndoable($root, $batch, $move, $userId);
			} catch (ToolFailure $e) {
				$conflicts[] = ['from' => $move['from'], 'to' => $move['to'], 'reason' => $e->getMessage()];
			}
		}
		return $conflicts;
	}

	/**
	 * @param Folder $root the user's folder
	 * @param Batch $batch the batch the move came from
	 * @param array{from:string, to:string, toId:int} $move one recorded move
	 * @param string $userId authenticated user
	 * @throws ToolFailure when the item is not what the batch left there, the way back is taken, the permissions
	 *         changed or a lock refuses the move back
	 * @return void
	 */
	private function assertUndoable(Folder $root, Batch $batch, array $move, string $userId): void {
		$there = NodeAccess::run(fn () => NodeAccess::get($root, $move['to']));
		if ((int)$there->getId() !== $move['toId']) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::notTheBatchNode());
		}
		$this->moveLocks->assertUnlocked($there, $userId);
		if ($root->nodeExists(ltrim(PathGuard::normalize($move['from'], 'moves'), '/'))) {
			throw new MoveConflict(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
		}
		if (!$there->isDeletable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		try {
			$parent = $this->moveDestination->destination($root, $move['from']);
		} catch (ToolFailure $e) {
			// The way back lands in a folder this batch created, so the undo puts the folder back first. Only a
			// folder that is really missing counts: a refusal of one that exists (hidden, not writable) stands.
			if (!$this->isRecordedAncestor($batch, $move['from']) || $this->parentExists($root, $move['from'])) {
				throw $e;
			}
			return;
		}
		if (!$parent->isCreatable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path user-relative path
	 * @return bool whether the folder that would hold the path exists
	 */
	private function parentExists(Folder $root, string $path): bool {
		$parent = trim(dirname(PathGuard::normalize($path, 'moves')), '/');
		return $parent === '' || $root->nodeExists($parent);
	}

	/**
	 * @param Batch $batch the batch that ran
	 * @param string $from original path of a recorded move
	 * @return bool whether a folder the batch created is the parent of, or an ancestor above, that path
	 */
	private function isRecordedAncestor(Batch $batch, string $from): bool {
		$segments = PathGuard::segments(PathGuard::normalize($from));
		array_pop($segments);
		while ($segments !== []) {
			if (in_array('/' . implode('/', $segments), $batch->dirs, true)) {
				return true;
			}
			array_pop($segments);
		}
		return false;
	}
}
