<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Access\FolderExposureGate;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

final class BatchUndo {
    public function __construct(
        private MoveInspection $moveInspection,
        private FolderPreparation $folderPreparation,
        private MoveExecution $moveExecution,
        private BatchVisibility $batchVisibility,
        private FilesAccessGuard $visibilityGuard,
    ) {}

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
            throw new ToolFailure(ReorganizationMessages::batchNotFound());
        }
        if ($batch->isUndone()) {
            throw new ToolFailure(ReorganizationMessages::batchAlreadyUndone());
        }
        // Before the conflicts: they carry paths and reasons, and none may be published for a hidden item.
        FolderExposureGate::assertMoves($root, $batch->moves, $this->visibilityGuard, undo: true);
        $this->batchVisibility->assertUndoVisible($root, $batch);
        $conflicts = $this->undoConflicts($root, $batch, $userId);
        if ($conflicts !== []) {
            return ['batch_id' => $batchId, 'undone' => 0, 'removed_dirs' => [], 'kept_dirs' => [], 'conflicts' => $conflicts];
        }
        // A way back that lands in a folder this batch created needs that folder again, and recreating only
        // what is actually missing keeps the undo from bringing back a folder the user emptied on purpose.
        $this->folderPreparation->createFolders($root, $this->missingRecordedParents($root, $batch));
        $undone = 0;
        foreach (array_reverse($batch->moves) as $move) {
            try {
                $this->batchVisibility->assertPathsVisible($root, [$move['to'], $move['from']]);
                $node = NodeAccess::run(fn () => NodeAccess::get($root, $move['to']));
                FolderExposureGate::assertMove($root, $node, $move['from'], $this->visibilityGuard);
                NodeAccess::run(fn () => $this->moveExecution->locked(fn (): Node => $node->move(MovePaths::absolute($root, $move['from'])), $node, $userId));
                $undone++;
                $this->batchVisibility->assertPathsVisible($root, [$move['to'], $move['from']]);
            } catch (\Throwable $e) {
                // Every condition was checked above, so this is a lock or a permission that changed in
                // between. The row keeps only what did not go back: an item that already moved is no longer
                // in its destination, and leaving it there would make the next attempt read that absence as
                // a conflict and refuse the batch for good. So the retry picks up exactly the rest. Any exception
                // counts, as in the run of the batch: one Nextcloud has no typed answer for leaves the same state,
                // and what it says is not repeated to the caller.
                $store->keepRemaining($batchId, $userId, array_slice($batch->moves, 0, count($batch->moves) - $undone));
                $this->batchVisibility->assertUndoVisible($root, $batch);
                return ['batch_id' => $batchId, 'undone' => $undone, 'removed_dirs' => [], 'kept_dirs' => [],
                    'conflicts' => [['from' => $move['from'], 'to' => $move['to'],
                        'reason' => $e instanceof ToolFailure ? $e->getMessage() : ReorganizationMessages::moveFailed()]]];
            }
        }
        // All moves have returned; a later privacy refusal must not leave them
        // pending in the record and make every retry report absent destinations.
        $store->keepRemaining($batchId, $userId, []);
        $this->batchVisibility->assertUndoVisible($root, $batch);
        $removed = [];
        $kept = [];
        // Deepest first: a folder cannot go while something the undo just emptied is still inside it.
        foreach ($this->byDepth($batch->dirs) as $dir) {
            $this->batchVisibility->assertPathsVisible($root, [$dir]);
            $relative = ltrim($dir, '/');
            if (!$root->nodeExists($relative)) {
                continue;
            }
            $folder = $root->get($relative);
            if (!$folder instanceof Folder || !$folder->isDeletable() || $folder->getDirectoryListing() !== []) {
                $kept[] = $dir;
                continue;
            }
            NodeAccess::run(fn () => NodeAccess::mutate(fn () => $folder->delete(), $this->visibilityGuard));
            $this->batchVisibility->assertPathsVisible($root, [$dir]);
            $removed[] = $dir;
        }
        $this->batchVisibility->assertUndoVisible($root, $batch);
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
            throw new ToolFailure(ReorganizationMessages::batchNotFound());
        }
        if ($batch->isUndone()) {
            throw new ToolFailure(ReorganizationMessages::batchAlreadyUndone());
        }
        FolderExposureGate::assertMoves($root, $batch->moves, $this->visibilityGuard, undo: true);
        $this->batchVisibility->assertUndoVisible($root, $batch);
        $conflicts = $this->undoConflicts($root, $batch, $userId);
        [$removed, $kept] = $this->emptyDirs($root, $batch);
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
            'message' => ReorganizationMessages::planUndoBatch(),
        ];
    }

    /**
     * Checks every move of a batch before the undo touches anything, in reverse order.
     *
     * @param Folder $root the user's folder
     * @param Batch $batch the batch to check
     * @param string $userId authenticated user
     * @return list<array{from:string, to:string, reason:string}> the items that block the undo, empty when it may run
     */
    private function undoConflicts(Folder $root, Batch $batch, string $userId): array {
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
     */
    private function assertUndoable(Folder $root, Batch $batch, array $move, string $userId): void {
        $there = NodeAccess::run(fn () => NodeAccess::get($root, $move['to']));
        if ((int)$there->getId() !== $move['toId']) {
            throw new ToolFailure(ReorganizationMessages::notTheBatchNode());
        }
        $this->moveInspection->assertUnlocked($there, $userId);
        if ($root->nodeExists(ltrim(PathGuard::normalize($move['from'], 'moves'), '/'))) {
            throw new MoveConflict(ReorganizationMessages::destinationExists());
        }
        if (!$there->isDeletable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        try {
            $parent = $this->moveInspection->destination($root, $move['from']);
        } catch (ToolFailure $e) {
            // The way back lands in a folder this batch created, so the undo puts the folder back first. Only a
            // folder that is really missing counts: a refusal of one that exists (hidden, not writable) stands.
            if (!$this->isRecordedAncestor($batch, $move['from']) || $this->parentExists($root, $move['from'])) {
                throw $e;
            }
            return;
        }
        if (!$parent->isCreatable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
    }

    /**
     * Which of the folders a batch created the undo would remove, and which it would keep.
     *
     * Only an empty folder the batch itself created goes; a folder with content, one the user cannot delete
     * and one that is not there any more are all kept. Reading it is what lets the plan answer the question
     * before anything is moved back, so it reads the tree as the undo will leave it: the items the batch moved
     * in are gone by the time the folders are looked at.
     *
     * @param Folder $root the user's folder
     * @param Batch $batch batch being undone
     * @return array{0: list<string>, 1: list<string>} the folders to remove and the folders to keep
     */
    private function emptyDirs(Folder $root, Batch $batch): array {
        $removed = [];
        $kept = [];
        $simulatedRemoved = array_map(static fn (array $move): string => PathGuard::normalize($move['to'], 'moves'), $batch->moves);
        foreach ($this->byDepth($batch->dirs) as $dir) {
            $relative = ltrim($dir, '/');
            if (!$root->nodeExists($relative)) {
                continue;
            }
            $folder = $root->get($relative);
            if (!$folder instanceof Folder || !$folder->isDeletable()) {
                $kept[] = $dir;
                continue;
            }
            $listing = $folder->getDirectoryListing();
            $remaining = array_filter(
                $listing,
                function ($child) use ($root, $simulatedRemoved): bool {
                    $childPath = '/' . ltrim($root->getRelativePath($child->getPath()) ?? '', '/');
                    return !in_array($childPath, $simulatedRemoved, true);
                }
            );
            if ($remaining !== []) {
                $kept[] = $dir;
                continue;
            }
            $removed[] = $dir;
            $simulatedRemoved[] = $dir;
        }
        return [$removed, $kept];
    }

    /**
     * Orders paths deepest first so undo removes empty children before their parents.
     *
     * @param list<string> $paths user-relative folder paths
     * @return list<string> paths sorted by descending depth
     */
    private function byDepth(array $paths): array {
        $byDepth = $paths;
        usort($byDepth, fn (string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        return $byDepth;
    }

    /**
     * The folders this batch created that a move needs again to go back, and that are not there anymore.
     *
     * @param Folder $root the user's folder
     * @param Batch $batch the batch being undone
     * @return list<string> the missing folders, shallowest first
     */
    private function missingRecordedParents(Folder $root, Batch $batch): array {
        $missing = [];
        foreach ($batch->moves as $move) {
            $segments = PathGuard::segments(PathGuard::normalize($move['from'], 'moves'));
            array_pop($segments);
            while ($segments !== []) {
                $candidate = '/' . implode('/', $segments);
                if ($root->nodeExists(ltrim($candidate, '/'))) {
                    break;
                }
                if (in_array($candidate, $batch->dirs, true)) {
                    $missing[] = $candidate;
                }
                array_pop($segments);
            }
        }
        return $missing;
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
