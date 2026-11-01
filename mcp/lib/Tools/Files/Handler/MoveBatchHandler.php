<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Tools\Files\{CopyMoveContext, BatchStore, Batch, MovePlanner, FolderExposureGate, FilesMessages};
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Dedicated ordered batch move planning and execution, with complete type preflight before effects. */
final class MoveBatchHandler {
	/** @param CopyMoveContext $context Native inspection, policy and locking services. */
	public function __construct(private CopyMoveContext $context) {
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
		$result = $planner->plan($root, $userId, $moves, $mkdirs);
		if (!$result->isOk()) {
			// A folder the batch cannot create is an item without permission too: nothing of the list runs.
			$refusedDirs = array_filter($result->plan['mkdirs'], static fn (array $dir): bool => isset($dir['reason']));
			throw new ToolFailure(FilesMessages::batchNotOk(
				count($result->plan['conflicts']),
				count($result->plan['denied']) + count($refusedDirs),
			));
		}
		if ($result->confirmation !== null && !$confirmedShared) {
			// The plan travels with the confirmation: the agent shows the user the same list either way.
			return array_merge($result->confirmation, $result->plan);
		}
		$created = $this->context->reorganization->createFolders($root, $mkdirs);
		$moved = [];
		foreach ($result->planned as $index => $item) {
			try {
				$check = $this->context->reorganization->inspect($root, $item['from'], $item['to']);
				FolderExposureGate::assertMove($root, $check->source, $check->to, $this->context->visibilityGuard);
				$movedNode = NodeAccess::run(fn () => $this->context->locked(fn (): Node => $this->context->policy()->write($check->source, $check->to, fn (): Node => $check->source->move($this->context->absolute($root, $check->to))), $check->source, $userId));
				$moved[] = ['from' => $item['from'], 'to' => $check->to, 'toId' => (int)$movedNode->getId()];
				$this->context->visibilityGuard?->assertVisible($movedNode);
			} catch (\Throwable $e) {
				$failure = $e instanceof ToolFailure ? $e->getMessage() : FilesMessages::moveFailed();
				$batchId = $store->insert(new Batch(null, $userId, $now, $moved, $created, null));
				$this->context->reorganization->assertUndoVisible($root, new Batch(null, $userId, $now, $result->planned, $created, null));
				return [
					'batch_id' => $batchId,
					'moved' => $moved,
					'created_dirs' => $created,
					'failed' => ['from' => $item['from'], 'to' => $item['to'], 'reason' => $failure],
					'not_attempted' => array_slice($result->planned, $index + 1),
				];
			}
		}
		$batchId = $store->insert(new Batch(null, $userId, $now, $moved, $created, null));
		$this->context->reorganization->assertUndoVisible($root, new Batch(null, $userId, $now, $result->planned, $created, null));
		return [
			'batch_id' => $batchId,
			'moved' => $moved,
			'created_dirs' => $created,
			'undos' => count($moved),
		];
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
		$result = $planner->plan($root, $userId, $moves, $mkdirs);
		$plan = $result->plan;
		unset($plan['dryRun']);
		return [
			'action' => 'files_move_batch',
			'order' => array_map(static fn (array $item): array => ['from' => $item['from'], 'to' => $item['to']], $moves),
			'undo' => 'files_undo_batch with the batch_id the run returns',
			'requiresSharedConfirmation' => $result->confirmation !== null,
			'message' => FilesMessages::planBatch(),
		] + $plan;
	}

}
