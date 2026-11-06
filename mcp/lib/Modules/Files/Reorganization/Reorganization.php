<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Expose the public Files reorganization facade and compatibility delegations. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCP\Files\Folder;
use OCP\Files\Node;

/** Delegates public Files operations to focused, acyclic collaborators. */
final class Reorganization {
	/** Deepest accepted tree walk. */
	public const MAX_DEPTH = 5;
	/** Largest accepted tree listing. */
	public const MAX_ENTRIES = 2000;

	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param TreeWalk $treeWalk treeWalk collaborator.
	 * @param FolderCreator $folderCreator folderCreator collaborator.
	 * @param CopyMove $copyMove copyMove collaborator.
	 * @param BatchMover $batchMover batchMover collaborator.
	 * @param BatchUndo $batchUndo batchUndo collaborator.
	 * @param FolderPlan $folderPlan folderPlan collaborator.
	 * @param MoveLocks $moveLocks moveLocks collaborator.
	 * @param TreeMeasure $treeMeasure treeMeasure collaborator.
	 * @param CopyMoveContext $context context collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly TreeWalk $treeWalk,
		private readonly FolderCreator $folderCreator,
		private readonly CopyMove $copyMove,
		private readonly BatchMover $batchMover,
		private readonly BatchUndo $batchUndo,
		private readonly FolderPlan $folderPlan,
		private readonly MoveLocks $moveLocks,
		private readonly TreeMeasure $treeMeasure,
		private readonly CopyMoveContext $context,
	) {
	}

	/**
	 * Delegate tree to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $path path input.
	 * @param int $depth depth input.
	 * @param int $limit limit input.
	 * @return array
	 */
	public function tree(Folder $root, string $userId, string $path, int $depth, int $limit): array { return $this->treeWalk->tree($root, $userId, $path, $depth, $limit); }

	/**
	 * Delegate mkdir to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $path path input.
	 * @param bool $confirmedShared confirmedShared input.
	 * @return array
	 */
	public function mkdir(Folder $root, string $userId, string $path, bool $confirmedShared): array { return $this->folderCreator->mkdir($root, $userId, $path, $confirmedShared); }

	/**
	 * Delegate copy to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $from from input.
	 * @param string $to to input.
	 * @param ?string $etag etag input.
	 * @param bool $confirmedShared confirmedShared input.
	 * @return array
	 */
	public function copy(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array { return $this->copyMove->copy($root, $userId, $from, $to, $etag, $confirmedShared); }

	/**
	 * Delegate move to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $from from input.
	 * @param string $to to input.
	 * @param ?string $etag etag input.
	 * @param bool $confirmedShared confirmedShared input.
	 * @return array
	 */
	public function move(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array { return $this->copyMove->move($root, $userId, $from, $to, $etag, $confirmedShared); }

	/**
	 * Delegate batch to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param array $moves moves input.
	 * @param array $mkdirs mkdirs input.
	 * @param bool $confirmedShared confirmedShared input.
	 * @param MovePlanner $planner planner input.
	 * @param BatchStore $store store input.
	 * @param int $now now input.
	 * @return array
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
	): array { return $this->batchMover->batch($root, $userId, $moves, $mkdirs, $confirmedShared, $planner, $store, $now); }

	/**
	 * Delegate undoBatch to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param int $batchId batchId input.
	 * @param BatchStore $store store input.
	 * @param int $now now input.
	 * @return array
	 */
	public function undoBatch(Folder $root, string $userId, int $batchId, BatchStore $store, int $now): array { return $this->batchUndo->undoBatch($root, $userId, $batchId, $store, $now); }

	/**
	 * Delegate planMkdir to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $path path input.
	 * @return array
	 */
	public function planMkdir(Folder $root, string $userId, string $path): array { return $this->folderPlan->planMkdir($root, $userId, $path); }

	/**
	 * Delegate planCopy to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $from from input.
	 * @param string $to to input.
	 * @param ?string $etag etag input.
	 * @return array
	 */
	public function planCopy(Folder $root, string $userId, string $from, string $to, ?string $etag): array { return $this->copyMove->planCopy($root, $userId, $from, $to, $etag); }

	/**
	 * Delegate planMove to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $from from input.
	 * @param string $to to input.
	 * @param ?string $etag etag input.
	 * @return array
	 */
	public function planMove(Folder $root, string $userId, string $from, string $to, ?string $etag): array { return $this->copyMove->planMove($root, $userId, $from, $to, $etag); }

	/**
	 * Delegate planBatch to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param array $moves moves input.
	 * @param array $mkdirs mkdirs input.
	 * @param MovePlanner $planner planner input.
	 * @return array
	 */
	public function planBatch(Folder $root, string $userId, array $moves, array $mkdirs, MovePlanner $planner): array { return $this->batchMover->planBatch($root, $userId, $moves, $mkdirs, $planner); }

	/**
	 * Delegate planUndoBatch to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param int $batchId batchId input.
	 * @param BatchStore $store store input.
	 * @param int $now now input.
	 * @return array
	 */
	public function planUndoBatch(Folder $root, string $userId, int $batchId, BatchStore $store, int $now): array { return $this->batchUndo->planUndoBatch($root, $userId, $batchId, $store, $now); }

	/**
	 * Delegate assertUnlocked to its focused owner.
	 * @param Node $node node input.
	 * @param string $userId userId input.
	 * @return void
	 */
	public function assertUnlocked(Node $node, string $userId): void { $this->moveLocks->assertUnlocked($node, $userId); }

	/**
	 * Delegate measure to its focused owner.
	 * @param Node $node node input.
	 * @return array
	 */
	public function measure(Node $node): array { return $this->treeMeasure->measure($node); }

	/**
	 * Delegate createFolders to its focused owner.
	 * @param Folder $root root input.
	 * @param array $paths paths input.
	 * @return array
	 */
	public function createFolders(Folder $root, array $paths): array { return $this->folderCreator->createFolders($root, $paths); }

	/** @return CopyMoveContext Immutable collaborator bindings shared by planners and effects. */
	public function copyMoveContext(): CopyMoveContext { return $this->context; }
}
