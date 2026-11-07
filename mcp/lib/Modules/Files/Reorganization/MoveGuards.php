<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Expose shared native rules without depending on handlers or operation context. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Delegates native rules without depending on the public facade or operation context. */
final class MoveGuards {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param MoveInspection $moveInspection moveInspection collaborator.
	 * @param MoveLocks $moveLocks moveLocks collaborator.
	 * @param BatchVisibility $batchVisibility batchVisibility collaborator.
	 * @param UndoConflicts $undoConflicts undoConflicts collaborator.
	 * @param BatchDirectories $batchDirectories batchDirectories collaborator.
	 * @param FolderCreator $folderCreator folderCreator collaborator.
	 * @param FolderBoundary $folderBoundary folderBoundary collaborator.
	 * @param TreeMeasure $treeMeasure treeMeasure collaborator.
	 * @param MoveDestination $moveDestination moveDestination collaborator.
	 * @param MoveReceipt $moveReceipt moveReceipt collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly MoveInspection $moveInspection,
		private readonly MoveLocks $moveLocks,
		private readonly BatchVisibility $batchVisibility,
		private readonly UndoConflicts $undoConflicts,
		private readonly BatchDirectories $batchDirectories,
		private readonly FolderCreator $folderCreator,
		private readonly FolderBoundary $folderBoundary,
		private readonly TreeMeasure $treeMeasure,
		private readonly MoveDestination $moveDestination,
		private readonly MoveReceipt $moveReceipt,
	) {
	}

	/**
	 * Delegate inspect to its focused owner.
	 * @param Folder $root root input.
	 * @param string $from from input.
	 * @param string $to to input.
	 * @param ?string $etag etag input.
	 * @param array $mkdirs mkdirs input.
	 * @return Inspection
	 */
	public function inspect(Folder $root, string $from, string $to, ?string $etag = null, array $mkdirs = []): Inspection { return $this->moveInspection->inspect($root, $from, $to, $etag, $mkdirs); }

	/**
	 * Delegate assertUnlocked to its focused owner.
	 * @param Node $node node input.
	 * @param string $userId userId input.
	 * @return void
	 */
	public function assertUnlocked(Node $node, string $userId): void { $this->moveLocks->assertUnlocked($node, $userId); }

	/**
	 * Delegate locked to its focused owner.
	 * @param callable $write write input.
	 * @param Node $node node input.
	 * @param string $userId userId input.
	 * @return Node
	 */
	public function locked(callable $write, Node $node, string $userId): Node { return $this->moveLocks->locked($write, $node, $userId); }

	/**
	 * Delegate assertFolderMoves to its focused owner.
	 * @param Folder $root root input.
	 * @param array $moves moves input.
	 * @param bool $undo undo input.
	 * @return void
	 */
	public function assertFolderMoves(Folder $root, array $moves, bool $undo = false): void { $this->batchVisibility->assertFolderMoves($root, $moves, $undo); }

	/**
	 * Delegate assertUndoVisible to its focused owner.
	 * @param Folder $root root input.
	 * @param Batch $batch batch input.
	 * @return void
	 */
	public function assertUndoVisible(Folder $root, Batch $batch): void { $this->batchVisibility->assertUndoVisible($root, $batch); }

	/**
	 * Delegate assertPathsVisible to its focused owner.
	 * @param Folder $root root input.
	 * @param array $paths paths input.
	 * @return void
	 */
	public function assertPathsVisible(Folder $root, array $paths): void { $this->batchVisibility->assertPathsVisible($root, $paths); }

	/**
	 * Delegate undoConflicts to its focused owner.
	 * @param Folder $root root input.
	 * @param Batch $batch batch input.
	 * @param string $userId userId input.
	 * @return array
	 */
	public function undoConflicts(Folder $root, Batch $batch, string $userId): array { return $this->undoConflicts->undoConflicts($root, $batch, $userId); }

	/**
	 * Delegate emptyDirs to its focused owner.
	 * @param Folder $root root input.
	 * @param Batch $batch batch input.
	 * @return array
	 */
	public function emptyDirs(Folder $root, Batch $batch): array { return $this->batchDirectories->emptyDirs($root, $batch); }

	/**
	 * Delegate byDepth to its focused owner.
	 * @param array $paths paths input.
	 * @return array
	 */
	public function byDepth(array $paths): array { return $this->batchDirectories->byDepth($paths); }

	/**
	 * Delegate missingRecordedParents to its focused owner.
	 * @param Folder $root root input.
	 * @param Batch $batch batch input.
	 * @return array
	 */
	public function missingRecordedParents(Folder $root, Batch $batch): array { return $this->batchDirectories->missingRecordedParents($root, $batch); }

	/**
	 * Delegate guardFolder to its focused owner.
	 * @param Folder $root root input.
	 * @param string $userId userId input.
	 * @param string $path path input.
	 * @return ?array
	 */
	public function guardFolder(Folder $root, string $userId, string $path): ?array { return $this->folderCreator->guardFolder($root, $userId, $path); }

	/**
	 * Delegate checkFolder to its focused owner.
	 * @param Folder $root root input.
	 * @param string $path path input.
	 * @return void
	 */
	public function checkFolder(Folder $root, string $path): void { $this->folderBoundary->checkFolder($root, $path); }

	/**
	 * Delegate createFolders to its focused owner.
	 * @param Folder $root root input.
	 * @param array $paths paths input.
	 * @return array
	 */
	public function createFolders(Folder $root, array $paths): array { return $this->folderCreator->createFolders($root, $paths); }

	/**
	 * Delegate measure to its focused owner.
	 * @param Node $node node input.
	 * @return array
	 */
	public function measure(Node $node): array { return $this->treeMeasure->measure($node); }

	/**
	 * Delegate writable to its focused owner.
	 * @param Folder $root root input.
	 * @param string $path path input.
	 * @return Node
	 */
	public function writable(Folder $root, string $path): Node { return $this->moveInspection->writable($root, $path); }

	/**
	 * Delegate assertFree to its focused owner.
	 * @param Folder $root root input.
	 * @param string $to to input.
	 * @return void
	 */
	public function assertFree(Folder $root, string $to): void { $this->moveDestination->assertFree($root, $to); }

	/**
	 * Delegate destination to its focused owner.
	 * @param Folder $root root input.
	 * @param string $path path input.
	 * @param array $mkdirs mkdirs input.
	 * @return Folder
	 */
	public function destination(Folder $root, string $path, array $mkdirs = []): Folder { return $this->moveDestination->destination($root, $path, $mkdirs); }

	/**
	 * Delegate confirmBoth to its focused owner.
	 * @param Node $source source input.
	 * @param string $sourcePath sourcePath input.
	 * @param Folder $destination destination input.
	 * @param string $destinationPath destinationPath input.
	 * @param string $userId userId input.
	 * @param bool $confirmedShared confirmedShared input.
	 * @return ?array
	 */
	public function confirmBoth(
		Node $source,
		string $sourcePath,
		Folder $destination,
		string $destinationPath,
		string $userId,
		bool $confirmedShared,
	): ?array { return $this->moveReceipt->confirmBoth($source, $sourcePath, $destination, $destinationPath, $userId, $confirmedShared); }

	/**
	 * Delegate sharedOf to its focused owner.
	 * @param string $userId userId input.
	 * @param Node $nodes nodes input.
	 * @return array
	 */
	public function sharedOf(string $userId, Node ...$nodes): array { return $this->moveReceipt->sharedOf($userId, ...$nodes); }

	/**
	 * Delegate versions to its focused owner.
	 * @param Node $node node input.
	 * @param string $userId userId input.
	 * @return ?int
	 */
	public function versions(Node $node, string $userId): ?int { return $this->moveReceipt->versions($node, $userId); }

	/**
	 * Delegate absolute to its focused owner.
	 * @param Folder $root root input.
	 * @param string $path path input.
	 * @return string
	 */
	public function absolute(Folder $root, string $path): string { return $this->moveDestination->absolute($root, $path); }

	/**
	 * The backup folder holds the copies of what MCP overwrote, and they stay hidden only while they sit where the
	 * mirror rule expects them: moving, copying or renaming anything in or into it is refused, like an edit is.
	 *
	 * @param string $path normalized user-relative path, a source or a destination
	 * @return string the same path
	 * @throws ToolFailure when the path is /MCP backups or inside it
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $location location input.
	 */
	public static function notBackup(string $path, ?\OCA\Mcp\Platform\Backups\BackupLocation $location = null): string {
		return MoveInspection::notBackup($path, $location);
	}
}
