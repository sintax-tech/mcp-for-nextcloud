<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

final class MoveInspection {
    public function __construct(
        private NodeAccessInfo $access,
        private SharedWriteGuard $guard,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

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
        $to = MovePaths::notBackup(PathGuard::normalize($to, 'to'));
        // A folder landing inside itself is refused before the destination check, so the user hears the real
        // reason instead of "something is already there" for a subfolder of the folder they are moving.
        $this->assertNotIntoItself($root, $source, $to);
        $this->assertFree($root, $to);
        $destination = $this->destination($root, $to, $mkdirs);
        $this->assertSameStorage($source, $destination);
        return new Inspection($source, $destination, $to);
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
        return $this->guard->guard(MovePaths::existingAncestor($root, $path), $userId, $path, false);
    }

    /**
     * Refuses to move a node that files_lock locks against the user: open in Text or Office, locked by another person
     * or a WebDAV client. A folder is refused when anything the user can reach inside it is, or is too large to check.
     *
     * @param Node $node the node about to move
     * @param string $userId authenticated user
     * @throws \OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure when a lock stops the move
     */
    public function assertUnlocked(Node $node, string $userId): void {
        $this->locks?->assertWritable($node, $userId);
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
        $path = MovePaths::notBackup(PathGuard::normalize($path));
        $node = NodeAccess::run(fn () => NodeAccess::get($root, $path, $this->visibilityGuard));
        if (!$node->isUpdateable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        return $node;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $to destination path, user-relative
     * @throws ToolFailure when anything already sits there
     */
    public function assertFree(Folder $root, string $to): void {
        $rel = ltrim($to, '/');
        if ($to === '/' || $root->nodeExists($rel)) {
            if ($to !== '/') {
                try {
                    $existing = $root->get($rel);
                    if (!$this->visibilityGuard->isVisible($existing)) {
                        throw new ToolFailure(FileAccessMessages::notFound());
                    }
                } catch (ToolFailure $e) {
                    throw $e;
                } catch (\Throwable) {
                    throw new ToolFailure(FileAccessMessages::notFound());
                }
            }
            throw new MoveConflict(ReorganizationMessages::destinationExists());
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
            throw new MoveConflict(ReorganizationMessages::folderIntoItself());
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
            throw new MoveConflict(ReorganizationMessages::crossStorage());
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
            throw new ToolFailure(FileAccessMessages::forbidden());
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
}
