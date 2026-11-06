<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Coordinate the existing batch undo preview and effect handlers. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Preserves the existing BatchUndo operations and refusal order. */
final class BatchUndo {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param CopyMoveContext $context context collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly CopyMoveContext $context,
	) {
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
		return (new \OCA\Mcp\Modules\Files\Reorganization\UndoBatchHandler($this->context))->undoBatch($root, $userId, $batchId, $store, $now);
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
		return (new \OCA\Mcp\Modules\Files\Reorganization\UndoBatchHandler($this->context))->planUndoBatch($root, $userId, $batchId, $store, $now);
	}
}
