<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Tools\Files\{CopyMoveContext, BatchStore, Batch, FileTypePreflight, FolderExposureGate, FilesMessages};
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Dedicated ordered inverse batch planning and execution, with complete type preflight before effects. */
final class UndoBatchHandler {
	/** @param CopyMoveContext $context Native inspection, policy and locking services. */
	public function __construct(private CopyMoveContext $context) {
	}

	/**
	 * Gives a batch back what it moved, and removes the folders it created while they are still empty.
	 *
	 * The whole batch is checked before anything moves: undoing half of it would overwrite what the user did
	 * afterwards and still leave a mess, so a single divergence refuses the whole thing and names the item.
	 * The only removal here is an empty folder this batch created, which is why the delete is written here
	 * and not in a helper — the allowance is scoped to this method on purpose.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param int $batchId the batch to undo
	 * @param BatchStore $store where the batch is recorded
	 * @param int $now current time
	 * @return array{batch_id:int, undone:int, removed_dirs:list<string>, kept_dirs:list<string>, conflicts:list<array{from:string, to:string, reason:string}>}
	 * @throws ToolFailure when the batch is not this user's, is gone, or was already undone
	 */
	public function undoBatch(Folder $root, string $userId, int $batchId, BatchStore $store, int $now): array {
		$batch = $store->find($batchId, $userId);
		// A batch of somebody else and one past its lifetime answer the same way, so nothing leaks about
		// which ids ever existed.
		if ($batch === null || $batch->createdAt < $now - BatchStore::LIFETIME_SECONDS) {
			throw new ToolFailure(FilesMessages::batchNotFound());
		}
		if ($batch->isUndone()) {
			throw new ToolFailure(FilesMessages::batchAlreadyUndone());
		}
		// Before the conflicts: they carry paths and reasons, and none may be published for a hidden item.
		$this->context->reorganization->assertFolderMoves($root, $batch->moves, undo: true);
		$this->context->reorganization->assertUndoVisible($root, $batch);
		$conflicts = $this->context->reorganization->undoConflicts($root, $batch, $userId);
		try {
			$types = (new FileTypePreflight($this->context))->moves($root, $batch->moves, $this->context->policy()->snapshot(), true);
			$conflicts = array_merge($conflicts, $types);
		} catch (\OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable $e) {
			$conflicts[] = ['from' => '', 'to' => '', 'reason' => $e->getMessage()];
		}
		if ($conflicts !== []) {
			return ['batch_id' => $batchId, 'undone' => 0, 'removed_dirs' => [], 'kept_dirs' => [], 'conflicts' => $conflicts];
		}
		// A way back that lands in a folder this batch created needs that folder again, and recreating only
		// what is actually missing keeps the undo from bringing back a folder the user emptied on purpose.
		$this->context->reorganization->createFolders($root, $this->context->reorganization->missingRecordedParents($root, $batch));
		$undone = 0;
		foreach (array_reverse($batch->moves) as $move) {
			try {
				$this->context->reorganization->assertPathsVisible($root, [$move['to'], $move['from']]);
				$node = NodeAccess::run(fn () => NodeAccess::get($root, $move['to']));
				FolderExposureGate::assertMove($root, $node, $move['from'], $this->context->visibilityGuard);
				NodeAccess::run(fn () => $this->context->locked(fn (): Node => $this->context->policy()->write($node, $move['from'], fn (): Node => $node->move($this->context->absolute($root, $move['from']))), $node, $userId));
				$undone++;
				$this->context->reorganization->assertPathsVisible($root, [$move['to'], $move['from']]);
			} catch (\Throwable $e) {
				// Every condition was checked above, so this is a lock or a permission that changed in
				// between. The row keeps only what did not go back: an item that already moved is no longer
				// in its destination, and leaving it there would make the next attempt read that absence as
				// a conflict and refuse the batch for good. So the retry picks up exactly the rest. Any exception
				// counts, as in the run of the batch: one Nextcloud has no typed answer for leaves the same state,
				// and what it says is not repeated to the caller.
				$store->keepRemaining($batchId, $userId, array_slice($batch->moves, 0, count($batch->moves) - $undone));
				$this->context->reorganization->assertUndoVisible($root, $batch);
				return ['batch_id' => $batchId, 'undone' => $undone, 'removed_dirs' => [], 'kept_dirs' => [],
					'conflicts' => [['from' => $move['from'], 'to' => $move['to'],
						'reason' => $e instanceof ToolFailure ? $e->getMessage() : FilesMessages::moveFailed()]]];
			}
		}
		// All moves have returned; a later privacy refusal must not leave them
		// pending in the record and make every retry report absent destinations.
		$store->keepRemaining($batchId, $userId, []);
		$this->context->reorganization->assertUndoVisible($root, $batch);
		$removed = [];
		$kept = [];
		// Deepest first: a folder cannot go while something the undo just emptied is still inside it.
		foreach ($this->context->reorganization->byDepth($batch->dirs) as $dir) {
			$this->context->reorganization->assertPathsVisible($root, [$dir]);
			$relative = ltrim($dir, '/');
			if (!$root->nodeExists($relative)) {
				continue;
			}
			$folder = $root->get($relative);
			if (!$folder instanceof Folder || !$folder->isDeletable() || $folder->getDirectoryListing() !== []) {
				$kept[] = $dir;
				continue;
			}
			NodeAccess::run(fn () => NodeAccess::mutate(fn () => $folder->delete(), $this->context->visibilityGuard));
			$this->context->reorganization->assertPathsVisible($root, [$dir]);
			$removed[] = $dir;
		}
		$this->context->reorganization->assertUndoVisible($root, $batch);
		$store->markUndone($batchId, $userId, $now);
		return [
			'batch_id' => $batchId,
			'undone' => $undone,
			'removed_dirs' => $removed,
			'kept_dirs' => $kept,
			'conflicts' => [],
		];
	}

	/**
	 * The plan of `files_undo_batch`: what would go back, what would be removed and what blocks the undo.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param int $batchId batch to undo
	 * @param BatchStore $store where the batch is recorded
	 * @param int $now current time
	 * @return array<string, mixed>
	 * @throws ToolFailure when the batch is not this user's, is gone, or was already undone
	 */
	public function planUndoBatch(Folder $root, string $userId, int $batchId, BatchStore $store, int $now): array {
		$batch = $store->find($batchId, $userId);
		if ($batch === null || $batch->createdAt < $now - BatchStore::LIFETIME_SECONDS) {
			throw new ToolFailure(FilesMessages::batchNotFound());
		}
		if ($batch->isUndone()) {
			throw new ToolFailure(FilesMessages::batchAlreadyUndone());
		}
		$this->context->reorganization->assertFolderMoves($root, $batch->moves, undo: true);
		$this->context->reorganization->assertUndoVisible($root, $batch);
		$conflicts = $this->context->reorganization->undoConflicts($root, $batch, $userId);
		try {
			$types = (new FileTypePreflight($this->context))->moves($root, $batch->moves, $this->context->policy()->snapshot(), true);
			$conflicts = array_merge($conflicts, $types);
		} catch (\OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable $e) {
			$conflicts[] = ['from' => '', 'to' => '', 'reason' => $e->getMessage()];
		}
		[$removed, $kept] = $this->context->reorganization->emptyDirs($root, $batch);
		return [
			'action' => 'files_undo_batch',
			'batch_id' => $batchId,
			'count' => count($batch->moves),
			'undo' => array_map(
				static fn (array $move): array => ['to' => $move['to'], 'from' => $move['from']],
				array_reverse($batch->moves),
			),
			'removed_dirs' => $removed,
			'kept_dirs' => $kept,
			'conflicts' => $conflicts,
			'ok' => $conflicts === [],
			'recoverable' => true,
			'message' => FilesMessages::planUndoBatch(),
		];
	}

}
