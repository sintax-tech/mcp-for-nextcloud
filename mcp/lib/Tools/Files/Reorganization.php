<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * The file-tree walk behind files_tree, and the home of every operation that reorganizes a folder.
 *
 * It exists so FilesModule only routes: the walk has its own bounds, its own ordering and its own
 * truncation rule, and the operations that follow in later blocks get the same helpers rather than a
 * second copy of the path resolution.
 *
 * Nothing here ever removes a node. The only deletion the Files module is allowed to perform is of an
 * empty folder the batch itself created, inside files_undo_batch.
 */
final class Reorganization {
    /** Deepest walk files_tree accepts. */
    public const MAX_DEPTH = 5;
    /** Most entries files_tree returns. */
    public const MAX_ENTRIES = 2000;

    public function __construct(
        private NodeAccessInfo $access,
        private SharedWriteGuard $guard,
        private MoveReport $report,
        private \OCP\IUserManager $userManager,
        private ?\OCA\Mcp\Service\Scope\FilesAccessGuard $visibilityGuard = null,
        private ?LockAwareWrite $locks = null,
        private ?\OCA\Mcp\Tools\Common\FileTypeGuard $fileTypes = null,
        private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
    ) {}

    /**
     * Walks a folder breadth-first, one level of `depth` at a time, stopping as soon as `limit` entries
     * have been collected — a cut walk must not read a whole tree to throw most of it away.
     *
     * Directories come before files inside each level and both are alphabetical, so the answer does not
     * depend on the order the storage happens to return, and so a truncated answer starts with the folders
     * an agent needs to see to plan.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path folder to walk, user-relative
     * @param int $depth how many levels below $path to include, 1 meaning only its direct entries
     * @param int $limit maximum number of entries
     * @return array{path:string, entries:list<array{name:string, path:string, isDir:bool, size:int, mtime:string, mime:string, access:array<string, mixed>}>, count:int, truncated:bool}
     * @throws \\InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure when the path is not a readable folder
     */
    public function tree(Folder $root, string $userId, string $path, int $depth, int $limit): array {
        $path = PathGuard::normalize($path);
        // With a Files scope that does not hold the root, the walk of "/" starts from the selected folders, which are
        // its first level at their real paths; the folders above them are never listed.
        $roots = $path === '/' ? $this->visibilityGuard?->scopeRoots($root) : null;
        if ($roots === null) {
            $start = NodeAccess::get($root, $path, $this->visibilityGuard);
            if (!$start instanceof Folder) {
                throw new ToolFailure(FilesMessages::notAFolder());
            }
        }
        $entries = [];
        $truncated = false;
        // Each level is a list of [node, depth below $path]; folders at the deepest level are not descended.
        $level = $roots === null ? [[$start, 0]] : [[null, 0]];
        for ($current = 0; $current < $depth && $level !== []; $current++) {
            $next = [];
            foreach ($level as [$folder, $levelBelow]) {
                foreach ($folder === null ? $roots : $this->sorted($folder) as $node) {
                    if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                        continue;
                    }
                    // The cut is only a cut once there is one more entry to leave out: a limit equal to the number
                    // of entries returns them all, and saying `truncated` then sends the agent after nothing.
                    if (count($entries) >= $limit) {
                        $truncated = true;
                        break 3;
                    }
                    $relative = $root->getRelativePath($node->getPath()) ?? '';
                    $entries[] = $this->entry($root, $userId, $node, $relative);
                    if ($node instanceof Folder && $levelBelow + 1 < $depth) {
                        $next[] = [$node, $levelBelow + 1];
                    }
                }
                if ($truncated) {
                    break 2;
                }
            }
            $level = $next;
        }
        return ['path' => $path, 'entries' => $entries, 'count' => count($entries), 'truncated' => $truncated];
    }

    /**
     * Creates one folder, and whatever parents are missing, refusing to touch a destination that is taken.
     *
     * The refusal is deliberate rather than a silent reuse: the point of the reorganization is to place
     * files in a tree the user described, and a folder that already holds something else would swallow
     * them without anyone deciding that.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path folder to create, user-relative
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @return array{path:string, created:bool, created_paths:list<string>}
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure when the destination is taken, inside the backup folder, denied, or shared without confirmation
     */
    public function mkdir(Folder $root, string $userId, string $path, bool $confirmedShared): array {
        $path = PathGuard::normalize($path);
        if ($path === '/') {
            throw new ToolFailure(FilesMessages::destinationExists());
        }
        if (FileBackup::isBackupPath($path, $this->backupLocation)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        $relPath = ltrim($path, '/');
        if ($root->nodeExists($relPath)) {
            if ($this->visibilityGuard !== null) {
                try {
                    $existing = $root->get($relPath);
                    if (!$this->visibilityGuard->isVisible($existing)) {
                        throw new ToolFailure(CommonMessages::notFound());
                    }
                } catch (ToolFailure $e) {
                    throw $e;
                } catch (\Throwable) {
                    throw new ToolFailure(CommonMessages::notFound());
                }
            }
            throw new ToolFailure(FilesMessages::destinationExists());
        }
        $parent = $this->existingAncestor($root, $path);
        if ($this->visibilityGuard !== null) {
            $this->visibilityGuard->assertVisible($parent);
        }
        if (($payload = $this->guard->guard($parent, $userId, $path, $confirmedShared)) !== null) {
            return ['path' => $path, 'created' => false, 'created_paths' => []] + $payload;
        }
        if (!$parent->isCreatable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        $created = $this->missingLevels($root, $path);
        $folder = NodeAccess::ensureFolder($root, $path, $this->visibilityGuard);
        $this->visibilityGuard?->assertVisible($folder);
        return ['path' => $path, 'created' => true, 'created_paths' => $created];
    }

    /**
     * The shared-content confirmation a folder creation needs, the one `files_mkdir` asks for.
     *
     * The folder is judged by the closest folder that already exists above it: that is where it is made, and
     * so whose people it reaches. A folder that is already there is not made, so it asks for nothing. The
     * batch plan calls this for each folder of `mkdirs`, so a folder no move uses is guarded like the rest.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path requested folder, user-relative
     * @return array<string, mixed>|null the guard payload, null when the folder is not made in a shared place
     * @throws \InvalidArgumentException for a malformed path
     */
    public function guardFolder(Folder $root, string $userId, string $path): ?array {
        $path = PathGuard::normalize($path);
        $relative = ltrim($path, '/');
        if ($relative === '' || $root->nodeExists($relative)) {
            return null;
        }
        return $this->guard->guard($this->existingAncestor($root, $path), $userId, $path, false);
    }

    /**
     * The closest folder above $path that already exists, without creating anything on the way.
     *
     * @param Folder $root the user's folder
     * @param string $path folder about to be created
     * @return Folder the existing ancestor, the user's own root at worst
     */
    private function existingAncestor(Folder $root, string $path): Folder {
        $segments = PathGuard::segments($path);
        while ($segments !== []) {
            array_pop($segments);
            $walk = '/' . implode('/', $segments);
            if ($root->nodeExists(ltrim($walk, '/'))) {
                $found = $root->get(ltrim($walk, '/'));
                if ($found instanceof Folder) {
                    return $found;
                }
            }
        }
        return $root;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path folder about to be created
     * @return list<string> user-relative paths of the levels that do not exist yet, shallowest first
     */
    private function missingLevels(Folder $root, string $path): array {
        $missing = [];
        $walk = '';
        foreach (PathGuard::segments($path) as $segment) {
            $walk .= '/' . $segment;
            if (!$root->nodeExists(ltrim($walk, '/'))) {
                $missing[] = $walk;
            }
        }
        return $missing;
    }

    /**
     * Copies a file or a folder to a free path, refusing before anything is copied when the source is over
     * the ceilings.
     *
     * A copy is a new node: it gets a new id and the description promises nothing about the shares or the
     * versions of the original, which the Nextcloud copy flow does not carry over.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @return array{from:string, to:string, idBefore:int, idAfter:int|null, nodes:int, bytes:int, access:array<string, mixed>|null, partial?:bool, created?:list<string>, conflicts?:list<array{path:string,reason:string}>, not_copied?:list<string>}|array<string, mixed>
     *   the receipt, or the shared-write confirmation instead of a receipt when one is still missing
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, a ceiling or a denied write
     */
    public function copy(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
        return (new \OCA\Mcp\Tools\Files\Handler\CopyHandler($this->copyMoveContext()))->handle($root, $userId, $from, $to, $etag, $confirmedShared);
    }

    /**
     * Moves or renames a node inside the same storage, refusing before anything changes when the guards
     * say no, and reporting afterwards what could be measured.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @return array{from:string, to:string, idBefore:int, idAfter:int, idPreserved:bool, versionsBefore:int|null, versionsAfter:int|null, sharesBefore:int|null, sharesAfter:int|null, access:array<string, mixed>}|array<string, mixed>
     *   the report, or the shared-write confirmation instead of a report when one is still missing
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, another storage, a folder into itself or a denied write
     */
    public function move(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
        return (new \OCA\Mcp\Tools\Files\Handler\MoveHandler($this->copyMoveContext()))->handle($root, $userId, $from, $to, $etag, $confirmedShared);
    }

    /**
     * Build the shared wiring for the single copy/move handlers.
     *
     * @return CopyMoveContext The context over this operation's native checks and policy.
     */
    public function copyMoveContext(): CopyMoveContext {
        return new CopyMoveContext($this, $this->access, $this->report, $this->visibilityGuard, $this->locks, $this->fileTypes, $this->backupLocation);
    }

    /**
     * Refuses to move a node that files_lock locks against the user: open in Text or Office, locked by another person
     * or a WebDAV client. A folder is refused when anything the user can reach inside it is, or is too large to check.
     *
     * @param Node $node the node about to move
     * @param string $userId authenticated user
     * @throws \OCA\Mcp\Tools\Common\LockWriteFailure when a lock stops the move
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

    /**
     * Resolves a move and runs every check on it, without moving anything.
     *
     * A single move and a batch plan both come through here on purpose: a plan that accepted a move the
     * tool would later refuse would send the user to approve something that cannot happen.
     *
     * @param Folder $root the user's folder
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param list<string> $mkdirs folders (including their parents) available only while planning
     * @return Inspection the node, the receiving folder or its existing ancestor, and the normalized destination
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws MoveConflict when the destination is taken, the folder would land inside itself, or the two ends are on different storages
     * @throws ToolFailure when the source is missing, unreadable or not changeable, or the destination cannot receive
     */
    public function inspect(Folder $root, string $from, string $to, ?string $etag = null, array $mkdirs = []): Inspection {
        $source = $this->writable($root, $from);
        NodeAccess::checkEtag($source, $etag);
        $to = self::notBackup(PathGuard::normalize($to, 'to'), $this->backupLocation);
        // A folder landing inside itself is refused before the destination check, so the user hears the real
        // reason instead of "something is already there" for a subfolder of the folder they are moving.
        $this->assertNotIntoItself($root, $source, $to);
        $this->assertFree($root, $to);
        $destination = $this->destination($root, $to, $mkdirs);
        $this->assertSameStorage($source, $destination);
        return new Inspection($source, $destination, $to);
    }

    /** Classify the whole batch before any lock checks, folder creation or mutation. */
    public function assertFolderMoves(Folder $root, array $moves, bool $undo = false): void {
        if ($this->visibilityGuard !== null && !$this->visibilityGuard->isConfigurationValid()) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        if (!FolderExposureGate::active($this->visibilityGuard)) {
            return;
        }
        foreach ($moves as $item) {
            $from = $undo ? $item['to'] : $item['from'];
            $to = $undo ? $item['from'] : $item['to'];
            try {
                $source = NodeAccess::run(fn (): Node => NodeAccess::get($root, $from, $this->visibilityGuard));
            } catch (ToolFailure) {
                // Existing planning/undo checks retain their normal missing/hidden/ACL refusals.
                continue;
            }
            FolderExposureGate::assertMove($root, $source, $to, $this->visibilityGuard);
        }
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
        return (new \OCA\Mcp\Tools\Files\Handler\MoveBatchHandler($this->copyMoveContext()))->batch($root, $userId, $moves, $mkdirs, $confirmedShared, $planner, $store, $now);
    }
    /**
     * The plan of `files_mkdir`: the levels that do not exist yet, and the folder they would be created in.
     *
     * Every check of the write runs here first, so a plan never describes a folder the tool would refuse.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path folder that would be created, user-relative
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure when the destination is taken, inside the backup folder or denied
     */
    public function planMkdir(Folder $root, string $userId, string $path): array {
        $path = PathGuard::normalize($path);
        if ($path === '/') {
            throw new ToolFailure(FilesMessages::destinationExists());
        }
        if (FileBackup::isBackupPath($path, $this->backupLocation)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        $rel = ltrim($path, '/');
        if ($root->nodeExists($rel)) {
            if ($this->visibilityGuard !== null) {
                try {
                    $existing = $root->get($rel);
                    if (!$this->visibilityGuard->isVisible($existing)) {
                        throw new ToolFailure(CommonMessages::notFound());
                    }
                } catch (ToolFailure $e) {
                    throw $e;
                } catch (\Throwable) {
                    throw new ToolFailure(CommonMessages::notFound());
                }
            }
            throw new ToolFailure(FilesMessages::destinationExists());
        }
        $parent = $this->existingAncestor($root, $path);
        if ($this->visibilityGuard !== null) {
            $this->visibilityGuard->assertVisible($parent);
        }
        if (!$parent->isCreatable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        return [
            'action' => 'files_mkdir',
            'path' => $path,
            'created' => $this->missingLevels($root, $path),
            'createdIn' => $root->getRelativePath($parent->getPath()) ?? '',
            'access' => $this->access->describe($parent, $userId),
            'shared' => $this->sharedOf($userId, $parent),
            'recoverable' => true,
            'message' => FilesMessages::planMkdir(),
        ];
    }

    /**
     * The plan of `files_copy`: both ends, what the copy weighs and the id the original keeps.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, a ceiling or a denied write
     */
    public function planCopy(Folder $root, string $userId, string $from, string $to, ?string $etag): array {
        return (new \OCA\Mcp\Tools\Files\Handler\CopyHandler($this->copyMoveContext()))->planCopy($root, $userId, $from, $to, $etag);
    }

    /**
     * The plan of `files_move`: where the node goes, what it carries and what still shares it.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws MoveConflict when the destination is taken, the folder would land inside itself, or the two ends are on different storages
     * @throws ToolFailure when the source is missing, unreadable, not changeable, or the destination cannot receive
     */
    public function planMove(Folder $root, string $userId, string $from, string $to, ?string $etag): array {
        return (new \OCA\Mcp\Tools\Files\Handler\MoveHandler($this->copyMoveContext()))->planMove($root, $userId, $from, $to, $etag);
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
        return (new \OCA\Mcp\Tools\Files\Handler\MoveBatchHandler($this->copyMoveContext()))->planBatch($root, $userId, $moves, $mkdirs, $planner);
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
        return (new \OCA\Mcp\Tools\Files\Handler\UndoBatchHandler($this->copyMoveContext()))->planUndoBatch($root, $userId, $batchId, $store, $now);
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
        return (new \OCA\Mcp\Tools\Files\Handler\UndoBatchHandler($this->copyMoveContext()))->undoBatch($root, $userId, $batchId, $store, $now);
    }

    /**
     * Refuses the whole undo when anything it would touch is hidden by a tag, directly or through a folder above it.
     *
     * What it touches: each item where the batch left it, the place it goes back to (the item already there, or the
     * nearest folder that exists above it) and each folder the batch created. The tags may have been set after the
     * batch ran, so the batch record proves nothing. The refusal names no path and no item.
     *
     * @param Folder $root the user's folder
     * @param Batch $batch the batch to undo
     * @throws ToolFailure when one of those nodes is hidden or cannot be checked
     */
    public function assertUndoVisible(Folder $root, Batch $batch): void {
        $paths = $batch->dirs;
        foreach ($batch->moves as $move) {
            $paths[] = $move['to'];
            $paths[] = $move['from'];
        }
        $this->assertPathsVisible($root, $paths);
    }

    /** @param list<string> $paths paths or nearest existing ancestors to check */
    public function assertPathsVisible(Folder $root, array $paths): void {
        if ($this->visibilityGuard === null) {
            return;
        }
        foreach ($paths as $path) {
            try {
                $segments = PathGuard::segments(PathGuard::normalize($path, 'moves'));
                // The node itself when it exists, otherwise the nearest folder above it that does, the user's
                // folder itself at the top.
                while ($segments !== [] && !$root->nodeExists(implode('/', $segments))) {
                    array_pop($segments);
                }
                $visible = $this->visibilityGuard->isVisible($segments === [] ? $root : $root->get(implode('/', $segments)));
            } catch (\Throwable) {
                $visible = false;
            }
            if (!$visible) {
                throw new ToolFailure(FilesMessages::batchHasHiddenItem());
            }
        }
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
     */
    private function assertUndoable(Folder $root, Batch $batch, array $move, string $userId): void {
        $there = NodeAccess::run(fn () => NodeAccess::get($root, $move['to']));
        if ((int)$there->getId() !== $move['toId']) {
            throw new ToolFailure(FilesMessages::notTheBatchNode());
        }
        $this->assertUnlocked($there, $userId);
        if ($root->nodeExists(ltrim(PathGuard::normalize($move['from'], 'moves'), '/'))) {
            throw new MoveConflict(FilesMessages::destinationExists());
        }
        if (!$there->isDeletable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        try {
            $parent = $this->destination($root, $move['from']);
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
     * @param list<string> $paths folders, in the order the batch created them
     * @return list<string> the same paths, deepest first
     */

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
    public function emptyDirs(Folder $root, Batch $batch): array {
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
     * Which of the given nodes reach people other than the caller, described the way the write would ask.
     *
     * @param string $userId authenticated user
     * @param Node ...$nodes ends of the operation: the source and the folder that would receive it
     * @return list<array<string, mixed>> the ownership description of each non-personal end, empty when all are personal
     */
    public function sharedOf(string $userId, Node ...$nodes): array {
        $shared = [];
        foreach ($nodes as $node) {
            $info = $this->access->describe($node, $userId);
            if ($info['scope'] !== NodeAccessInfo::PERSONAL) {
                $shared[] = $info;
            }
        }
        return $shared;
    }

    /**
     * Orders paths deepest first so undo removes empty children before their parents.
     *
     * @param list<string> $paths user-relative folder paths
     * @return list<string> paths sorted by descending depth
     */
    public function byDepth(array $paths): array {
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
    public function missingRecordedParents(Folder $root, Batch $batch): array {
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

    /**
     * Runs on one folder of a batch every check files_mkdir runs, without creating anything.
     *
     * The plan calls it for each folder so it never approves one the run would refuse, and the run calls it for
     * all of them again before the first folder is made.
     *
     * @param Folder $root the user's folder
     * @param string $path requested folder, user-relative
     * @throws \InvalidArgumentException for a malformed path
     * @throws ToolFailure when the folder is in the backup folder, a file already uses one of its names, it is or
     *   sits under a hidden node, or the folder that would receive it cannot take a new entry
     */
    public function checkFolder(Folder $root, string $path): void {
        $relative = ltrim(self::notBackup(PathGuard::normalize($path), $this->backupLocation), '/');
        $parent = $root;
        $walk = [];
        foreach (PathGuard::segments($relative) as $segment) {
            $walk[] = $segment;
            $below = implode('/', $walk);
            if (!$root->nodeExists($below)) {
                // The first missing level is created in the last one that exists, so that one has to be reachable,
                // inside the Files scope (the boundary of F5), and take it.
                $this->assertBoundary($parent);
                if (!$parent->isCreatable()) {
                    throw new ToolFailure(CommonMessages::forbidden());
                }
                return;
            }
            $existing = $root->get($below);
            // An existing level is crossed by metadata: excluded ones refuse, ancestors of a selected folder pass.
            if ($this->visibilityGuard !== null && !$this->visibilityGuard->isTraversable($existing)) {
                // Excluded destinations always use the same generic resource refusal.
                throw new ToolFailure(CommonMessages::notFound());
            }
            if (!$existing instanceof Folder) {
                // A file in the way is only named when the user may reach it: otherwise "it exists" would tell a
                // file outside the Files scope from a missing path (D6). A file is never an ancestor of a selected
                // folder, so this costs no traversal.
                $this->assertReachable($existing);
                throw new ToolFailure(FilesMessages::destinationExists());
            }
            $parent = $existing;
        }
        $this->assertBoundary($parent);
    }

    /**
     * @param Folder $folder the deepest existing folder of a destination
     * @throws ToolFailure not found when it is hidden or outside the Files scope
     */
    private function assertBoundary(Folder $folder): void {
        $this->assertReachable($folder);
    }

    /**
     * @param Node $node an existing node of a destination path, about to be named in a refusal or written below
     * @throws ToolFailure not found when it is hidden or outside the Files scope
     */
    private function assertReachable(Node $node): void {
        if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
    }

    /**
     * Creates the folders a batch asked for and answers which ones it really made.
     *
     * Every folder created counts, including a parent created on the way, because the undo removes what the
     * batch made and a folder it only implied is still one it made.
     *
     * @param Folder $root the user's folder
     * @param list<string> $paths requested folders
     * @return list<string> the created folders, shallowest first
     * @throws \InvalidArgumentException for a malformed path
     * @throws ToolFailure when {@see self::checkFolder()} refuses one of them, or the folder cannot be created
     */
    public function createFolders(Folder $root, array $paths): array {
        // Every folder is checked before the first one is made: stopping halfway would leave folders that no
        // recorded batch knows about, and so that no undo can remove.
        foreach ($paths as $path) {
            $this->checkFolder($root, $path);
        }
        $created = [];
        foreach ($paths as $path) {
            $relative = ltrim(PathGuard::normalize($path), '/');
            if ($relative === '') {
                continue;
            }
            $walk = [];
            foreach (PathGuard::segments($relative) as $segment) {
                $walk[] = $segment;
                $below = implode('/', $walk);
                if ($root->nodeExists($below)) {
                    $existing = $root->get($below);
                    if (!$existing instanceof Folder) {
                        $this->assertReachable($existing);
                        throw new ToolFailure(FilesMessages::destinationExists());
                    }
                    continue;
                }
                $folder = NodeAccess::run(fn () => NodeAccess::mutate(fn () => $root->newFolder($below), $this->visibilityGuard));
                $this->visibilityGuard?->assertVisible($folder);
                $created[] = '/' . $below;
            }
        }
        return $created;
    }

    /**
     * Counts the nodes and bytes a copy would bring, stopping as soon as a ceiling is passed so a huge
     * tree is not measured to the end just to be refused.
     *
     * A hidden node inside the tree stops the copy too: every copied node gets a new id, the hidden tag belongs
     * to the id of the original, and the copy of a hidden file would be readable by anyone who can read the
     * folder it lands in. The answer is the generic denial, so the count of what is hidden is never told.
     *
     * @param Node $node the node a copy would start from, a file or a whole folder
     * @return array{nodes:int, bytes:int}
     * @throws ToolFailure when the source is over either ceiling or holds a hidden node
     */
    public function measure(Node $node): array {
        $nodes = $bytes = 0;
        $pending = [$node];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current !== $node && $this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($current)) {
                throw new ToolFailure(CommonMessages::forbidden());
            }
            $nodes++;
            if ($nodes > ReorganizationLimits::NODES) {
                throw new ToolFailure(FilesMessages::copyTooManyNodes(ReorganizationLimits::NODES));
            }
            if ($current instanceof Folder) {
                foreach ($this->sorted($current) as $child) {
                    $pending[] = $child;
                }
                continue;
            }
            $bytes += (int)$current->getSize();
            if ($bytes > ReorganizationLimits::BYTES) {
                throw new ToolFailure(FilesMessages::copyTooLarge(ReorganizationLimits::BYTES));
            }
        }
        return ['nodes' => $nodes, 'bytes' => $bytes];
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path source path to resolve and check
     * @return Node the readable, writable source
     * @throws ToolFailure when it is in the backup folder, does not exist, cannot be read or cannot be changed
     */
    public function writable(Folder $root, string $path): Node {
        // run() turns a missing node into a ToolFailure here, so a batch plan can put the item in its
        // denied list instead of the whole call failing.
        $path = self::notBackup(PathGuard::normalize($path), $this->backupLocation);
        $node = NodeAccess::run(fn () => NodeAccess::get($root, $path, $this->visibilityGuard));
        if (!$node->isUpdateable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        return $node;
    }

    /**
     * The backup folder holds the copies of what MCP overwrote, and they stay hidden only while they sit where the
     * mirror rule expects them: moving, copying or renaming anything in or into it is refused, like an edit is.
     *
     * @param string $path normalized user-relative path, a source or a destination
     * @return string the same path
     * @throws ToolFailure when the path is /MCP backups or inside it
     */
    public static function notBackup(string $path, ?\OCA\Mcp\Service\BackupLocation $location = null): string {
        return BackupWriteBoundary::checked($path, $location);
    }

    /**
     * @param Folder $root the user's folder
     * @param string $to destination path, user-relative
     * @throws ToolFailure when anything already sits there
     */
    public function assertFree(Folder $root, string $to): void {
        $rel = ltrim($to, '/');
        if ($to === '/' || $root->nodeExists($rel)) {
            if ($to !== '/' && $this->visibilityGuard !== null) {
                try {
                    $existing = $root->get($rel);
                    if (!$this->visibilityGuard->isVisible($existing)) {
                        throw new ToolFailure(CommonMessages::notFound());
                    }
                } catch (ToolFailure $e) {
                    throw $e;
                } catch (\Throwable) {
                    throw new ToolFailure(CommonMessages::notFound());
                }
            }
            throw new MoveConflict(FilesMessages::destinationExists());
        }
    }

    /**
     * @param Folder $root the user's folder
     * @param Node $from the node being moved
     * @param string $to destination path, user-relative
     * @throws ToolFailure when a folder would land inside itself
     */
    private function assertNotIntoItself(Folder $root, Node $from, string $to): void {
        $source = $root->getRelativePath($from->getPath()) ?? '';
        if ($from instanceof Folder && ($to === $source || str_starts_with($to, rtrim($source, '/') . '/'))) {
            throw new MoveConflict(FilesMessages::folderIntoItself());
        }
    }

    /**
     * Nextcloud moves across storages by copying and deleting, which changes the id and re-versions the
     * file; the first scope of this sprint stays inside one storage so that the reported id is meaningful.
     *
     * @param Node $source the node being moved
     * @param Folder $destination the folder that would receive it
     * @throws MoveConflict when source and destination are on different storages
     */
    private function assertSameStorage(Node $source, Folder $destination): void {
        if ($source->getStorage()->getId() !== $destination->getStorage()->getId()) {
            throw new MoveConflict(FilesMessages::crossStorage());
        }
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path destination path, user-relative
     * @param list<string> $mkdirs planned folders whose existing ancestor supplies permissions and storage
     * @return Folder the destination folder, or the existing ancestor of a planned folder
     * @throws ToolFailure when it does not exist or cannot take new entries
     */
    public function destination(Folder $root, string $path, array $mkdirs = []): Folder {
        $segments = PathGuard::segments($path);
        array_pop($segments);
        $parentPath = '/' . implode('/', $segments);
        if ($parentPath !== '/' && !$root->nodeExists(ltrim($parentPath, '/'))) {
            $planned = false;
            foreach ($mkdirs as $dir) {
                $dir = PathGuard::normalize($dir);
                if ($dir === $parentPath || str_starts_with($dir, $parentPath . '/')) {
                    $planned = true;
                    break;
                }
            }
            if ($planned) {
                // Only mkdirs and their implied parents exist in the plan. Check the real ancestor:
                // a missing descendant must never bypass visibility, permissions or storage boundaries.
                do {
                    array_pop($segments);
                    $parentPath = '/' . implode('/', $segments);
                } while ($parentPath !== '/' && !$root->nodeExists(ltrim($parentPath, '/')));
            }
        }
        $parent = NodeAccess::run(fn () => NodeAccess::get($root, $parentPath, $this->visibilityGuard));
        if (!$parent instanceof Folder || !$parent->isCreatable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        return $parent;
    }

    /**
     * Both ends of the operation go through the guard: moving a file out of a team folder changes what the
     * team sees, and moving one into it adds something they did not put there.
     *
     * @param Node $source the node being moved or copied
     * @param string $sourcePath the source, as the user wrote it
     * @param Folder $destination the folder that would receive it
     * @param string $destinationPath the destination, as the user wrote it
     * @param string $userId authenticated user
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @return array<string, mixed>|null the confirmation payload, or null when the change may proceed
     */
    public function confirmBoth(
        Node $source,
        string $sourcePath,
        Folder $destination,
        string $destinationPath,
        string $userId,
        bool $confirmedShared,
    ): ?array {
        return $this->guard->guard($source, $userId, $sourcePath, $confirmedShared)
            ?? $this->guard->guard($destination, $userId, $destinationPath, $confirmedShared);
    }

    /**
     * Versions belong to files: a folder has none of its own, and asking anyway would report a count nobody
     * could check.
     *
     * @param Node $node the node to count versions of
     * @param string $userId authenticated user
     * @return int|null the version count, or null for a folder or when versioning is off
     */
    public function versions(Node $node, string $userId): ?int {
        return $node instanceof File ? $this->report->versions($node, $this->user($userId)) : null;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path user-relative path
     * @return string the absolute path in the user's own storage, which is what Node::move() takes
     */
    public function absolute(Folder $root, string $path): string {
        return $root->getPath() . '/' . ltrim($path, '/');
    }

    /**
     * @param string $userId authenticated user
     * @return \OCP\IUser|null the user, when the manager knows them
     */
    private function user(string $userId): ?\OCP\IUser {
        return $this->userManager->get($userId);
    }

    /**
     * @param Folder $folder folder whose entries are listed
     * @return list<Node> readable entries, folders first and both alphabetically, without the user's hidden files
     */
    private function sorted(Folder $folder): array {
        $entries = [];
        foreach ($folder->getDirectoryListing() as $node) {
            // A leading dot is what Nextcloud's own file views hide, and its trash and versions folders
            // are implementation detail an agent planning a reorganization has no business touching.
            if (str_starts_with($node->getName(), '.') || !$node->isReadable()) {
                continue;
            }
            $entries[] = $node;
        }
        usort($entries, static fn (Node $a, Node $b) => [$a instanceof Folder ? 0 : 1, $a->getName()]
            <=> [$b instanceof Folder ? 0 : 1, $b->getName()]);
        return $entries;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param Node $node entry to describe
     * @param string $relative user-relative path of the entry
     * @return array{name:string, path:string, isDir:bool, size:int, mtime:string, mime:string, access:array<string, mixed>}
     */
    private function entry(Folder $root, string $userId, Node $node, string $relative): array {
        $isDir = NodeAccess::isFolder($node);
        return [
            'name' => $node->getName(),
            'path' => $relative,
            'isDir' => $isDir,
            'size' => $isDir ? 0 : (int)$node->getSize(),
            'mtime' => gmdate('D, d M Y H:i:s \\G\\M\\T', (int)$node->getMTime()),
            'mime' => $isDir ? 'httpd/unix-directory' : (string)$node->getMimetype(),
            'access' => $this->access->describe($node, $userId),
        ];
    }
}
