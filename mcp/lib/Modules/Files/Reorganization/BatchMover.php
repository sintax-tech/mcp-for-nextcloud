<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Coordinate ordered batch move planning and execution. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Preserves the existing BatchMover operations and refusal order. */
final class BatchMover {
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
	 * Validates an ordered batch of folder creations and moves, then runs it. The plan is always rebuilt
	 * here, so a stale plan can never run. A plan that touches shared nodes is returned as a confirmation
	 * until `$confirmedShared` is set; the caller decides whether the user has already said yes. The run
	 * stops at the first failure and records what it did so the batch can be undone.
	 *
	 * A batch whose plan is not ok is refused rather than partly run: the user approved a plan, and
	 * quietly dropping the item that conflicts would leave a state neither they nor the agent asked for.
	 *
	 * If a privacy hook hides a result, the batch is recorded but the generic refusal omits its batch_id.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param list<array{from:string, to:string}> $moves requested moves
	 * @param list<string> $mkdirs folders to create before moving
	 * @param bool $confirmedShared whether the caller passed confirm_shared; without it a plan touching shared
	 *                              nodes comes back as a confirmation instead of running
	 * @param MovePlanner $planner the plan builder
	 * @param BatchStore $store where a run batch is recorded
	 * @param int $now current time
	 * @return array<string, mixed> the plan merged with the shared-node confirmation, or the outcome of the run
	 * @throws \InvalidArgumentException for a malformed path
	 * @throws ToolFailure when the plan is not ok, or the run itself failed
	 */
	public function batch(
		Folder $root,
		string $userId,
		array $moves,
		array $mkdirs,
		bool $confirmedShared,
		MovePlanner $planner,
		BatchStore $store,
		int $now,
	): array {
		return (new \OCA\Mcp\Modules\Files\Reorganization\MoveBatchHandler($this->context))->batch($root, $userId, $moves, $mkdirs, $confirmedShared, $planner, $store, $now);
	}

	/**
	 * The plan of `files_move_batch`: the ordered list the user approved, with everything that blocks it.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param list<array{from:string, to:string}> $moves requested moves
	 * @param list<string> $mkdirs folders the batch would create
	 * @param MovePlanner $planner the plan builder
	 * @return array<string, mixed>
	 */
	public function planBatch(Folder $root, string $userId, array $moves, array $mkdirs, MovePlanner $planner): array {
		return (new \OCA\Mcp\Modules\Files\Reorganization\MoveBatchHandler($this->context))->planBatch($root, $userId, $moves, $mkdirs, $planner);
	}
}
