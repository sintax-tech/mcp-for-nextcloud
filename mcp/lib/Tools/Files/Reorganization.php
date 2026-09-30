<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
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
        $start = NodeAccess::get($root, $path);
        if (!$start instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        $entries = [];
        $truncated = false;
        // Each level is a list of [node, depth below $path]; folders at the deepest level are not descended.
        $level = [[$start, 0]];
        for ($current = 0; $current < $depth && $level !== []; $current++) {
            $next = [];
            foreach ($level as [$folder, $levelBelow]) {
                foreach ($this->sorted($folder) as $node) {
                    $relative = $root->getRelativePath($node->getPath()) ?? '';
                    $entries[] = $this->entry($root, $userId, $node, $relative);
                    if (count($entries) >= $limit) {
                        $truncated = true;
                        break 3;
                    }
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
        if (FileBackup::isBackupPath($path)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        if ($root->nodeExists(ltrim($path, '/'))) {
            throw new ToolFailure(FilesMessages::destinationExists());
        }
        // The guard and the permission check run against the closest folder that already exists. Creating
        // the missing levels first and only then asking would leave the tree changed on a refusal, and
        // would ask about the wrong folder: the scope that matters is the one the new folder lands in.
        $parent = $this->existingAncestor($root, $path);
        if (($payload = $this->guard->guard($parent, $userId, $path, $confirmedShared)) !== null) {
            return ['path' => $path, 'created' => false, 'created_paths' => []] + $payload;
        }
        if (!$parent->isCreatable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        $created = $this->missingLevels($root, $path);
        NodeAccess::ensureFolder($root, $path);
        return ['path' => $path, 'created' => true, 'created_paths' => $created];
    }

    /**
     * The closest folder above $path that already exists, without creating anything on the way.
     *
     * @param Folder $root the user's folder
     * @param string $path folder about to be created
     * @return Folder the existing ancestor, the user's own root at worst
     */
    private function existingAncestor(Folder $root, string $path): Folder {
        $segments = array_values(array_filter(explode('/', $path)));
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
        foreach (array_filter(explode('/', $path)) as $segment) {
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
     * @return array{from:string, to:string, idBefore:int, idAfter:int, nodes:int, bytes:int, access:array<string, mixed>}|array<string, mixed>
     *   the receipt, or the shared-write confirmation instead of a receipt when one is still missing
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, a ceiling or a denied write
     */
    public function copy(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
        $source = $this->writable($root, $from);
        NodeAccess::checkEtag($source, $etag);
        $to = PathGuard::normalize($to);
        $this->assertFree($root, $to);
        $measured = $this->measure($source);
        if (($payload = $this->confirmBoth($source, $from, $this->destination($root, $to), $to, $userId, $confirmedShared)) !== null) {
            return $payload;
        }
        $receipt = $root->getRelativePath($source->getPath()) ?? '';
        $copy = $source->copy($this->absolute($root, $to));
        return [
            'from' => $receipt,
            'to' => $to,
            'idBefore' => (int)$source->getId(),
            'idAfter' => (int)$copy->getId(),
            'nodes' => $measured['nodes'],
            'bytes' => $measured['bytes'],
            'access' => $this->access->describe($copy, $userId),
        ];
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
        $check = $this->inspect($root, $from, $to, $etag);
        if (($payload = $this->confirmBoth($check->source, $from, $check->destination, $check->to, $userId, $confirmedShared)) !== null) {
            return $payload;
        }
        // Everything is read before the move: afterwards the node answers from its new path, and a receipt
        // read too late is a claim about nothing.
        $receipt = $root->getRelativePath($check->source->getPath()) ?? '';
        $idBefore = (int)$check->source->getId();
        $versionsBefore = $this->versions($check->source, $userId);
        $sharesBefore = $this->report->shares($check->source, $userId);
        $moved = $check->source->move($this->absolute($root, $check->to));
        $idAfter = (int)$moved->getId();
        return [
            'from' => $receipt,
            'to' => $check->to,
            'idBefore' => $idBefore,
            'idAfter' => $idAfter,
            'idPreserved' => $idAfter === $idBefore,
            'versionsBefore' => $versionsBefore,
            'versionsAfter' => $this->versions($moved, $userId),
            'sharesBefore' => $sharesBefore,
            'sharesAfter' => $this->report->shares($moved, $userId),
            'access' => $this->access->describe($moved, $userId),
        ];
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
     * @return Inspection the node, the receiving folder and the normalized destination
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws MoveConflict when the destination is taken, the folder would land inside itself, or the two ends are on different storages
     * @throws ToolFailure when the source is missing, unreadable or not changeable, or the destination cannot receive
     */
    public function inspect(Folder $root, string $from, string $to, ?string $etag = null): Inspection {
        $source = $this->writable($root, $from);
        NodeAccess::checkEtag($source, $etag);
        $to = PathGuard::normalize($to);
        // A folder landing inside itself is refused before the destination check, so the user hears the real
        // reason instead of "something is already there" for a subfolder of the folder they are moving.
        $this->assertNotIntoItself($root, $source, $to);
        $this->assertFree($root, $to);
        $destination = $this->destination($root, $to);
        $this->assertSameStorage($source, $destination);
        return new Inspection($source, $destination, $to);
    }

    /**
     * Plans or runs a batch. Planning writes nothing and records nothing; running needs the user's
     * confirmation, stops at the first failure and records what it did so the batch can be undone.
     *
     * A batch whose plan is not ok is refused rather than partly run: the user approved a plan, and
     * quietly dropping the item that conflicts would leave a state neither they nor the agent asked for.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param list<array{from:string, to:string}> $moves requested moves
     * @param list<string> $mkdirs folders to create before moving
     * @param bool $dryRun true to only plan
     * @param bool|null $confirmed the user's confirm, required to run
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @param MovePlanner $planner the plan builder
     * @param BatchStore $store where a run batch is recorded
     * @param int $now current time
     * @return array<string, mixed> the plan, the confirmation, or the outcome of the run
     * @throws \InvalidArgumentException for a malformed path, or a run without confirm
     * @throws ToolFailure when the plan is not ok, or the run itself failed
     */
    public function batch(
        Folder $root,
        string $userId,
        array $moves,
        array $mkdirs,
        bool $dryRun,
        ?bool $confirmed,
        bool $confirmedShared,
        MovePlanner $planner,
        BatchStore $store,
        int $now,
    ): array {
        $result = $planner->plan($root, $userId, $moves, $mkdirs);
        if ($dryRun) {
            // A non-personal item asks for confirmation in the plan as well: the agent shows the user the
            // list either way, and the code path is the same one files_move already has.
            return $result->confirmation === null ? $result->plan : array_merge($result->confirmation, $result->plan);
        }
        if ($confirmed !== true) {
            throw new \InvalidArgumentException('files_move_batch com dry_run: false exige confirm: true.');
        }
        if (!$result->isOk()) {
            throw new ToolFailure(FilesMessages::batchNotOk(
                count($result->plan['conflicts']),
                count($result->plan['denied']),
            ));
        }
        if ($result->confirmation !== null && !$confirmedShared) {
            // The plan travels with the confirmation: the agent shows the user the same list either way.
            return array_merge($result->confirmation, $result->plan, ['dryRun' => false]);
        }
        $created = $this->createFolders($root, $mkdirs);
        $moved = [];
        foreach ($result->planned as $index => $item) {
            try {
                $check = $this->inspect($root, $item['from'], $item['to']);
                $movedNode = NodeAccess::run(fn () => $check->source->move($this->absolute($root, $check->to)));
                $moved[] = ['from' => $item['from'], 'to' => $check->to, 'toId' => (int)$movedNode->getId()];
            } catch (\Throwable $e) {
                $failure = $e instanceof ToolFailure ? $e->getMessage() : 'Falha ao mover o item.';
                $batchId = $store->insert(new Batch(null, $userId, $now, $moved, $created, null));
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
        return [
            'batch_id' => $batchId,
            'moved' => $moved,
            'created_dirs' => $created,
            'undos' => count($moved),
        ];
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
     * @throws ToolFailure when a file already uses one of the folder names, or the folder cannot be created
     */
    public function createFolders(Folder $root, array $paths): array {
        $created = [];
        foreach ($paths as $path) {
            $relative = ltrim(PathGuard::normalize($path), '/');
            if ($relative === '') {
                continue;
            }
            $walk = [];
            foreach (array_filter(explode('/', $relative)) as $segment) {
                $walk[] = $segment;
                $below = implode('/', $walk);
                if ($root->nodeExists($below)) {
                    $existing = $root->get($below);
                    if (!$existing instanceof Folder) {
                        throw new ToolFailure(FilesMessages::destinationExists());
                    }
                    continue;
                }
                NodeAccess::run(fn () => $root->newFolder($below));
                $created[] = '/' . $below;
            }
        }
        return $created;
    }

    /**
     * Counts the nodes and bytes a copy would bring, stopping as soon as a ceiling is passed so a huge
     * tree is not measured to the end just to be refused.
     *
     * @param Node $node the node a copy would start from, a file or a whole folder
     * @return array{nodes:int, bytes:int}
     * @throws ToolFailure when the source is over either ceiling
     */
    public function measure(Node $node): array {
        $nodes = $bytes = 0;
        $pending = [$node];
        while ($pending !== []) {
            $current = array_pop($pending);
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
     * @throws ToolFailure when it does not exist, cannot be read or cannot be changed
     */
    private function writable(Folder $root, string $path): Node {
        // run() turns a missing node into a ToolFailure here, so a batch plan can put the item in its
        // denied list instead of the whole call failing.
        $node = NodeAccess::run(fn () => NodeAccess::get($root, PathGuard::normalize($path)));
        if (!$node->isUpdateable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        return $node;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $to destination path, user-relative
     * @throws ToolFailure when anything already sits there
     */
    private function assertFree(Folder $root, string $to): void {
        if ($to === '/' || $root->nodeExists(ltrim($to, '/'))) {
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
     * @return Folder the destination folder, which must already exist
     * @throws ToolFailure when it does not exist or cannot take new entries
     */
    private function destination(Folder $root, string $path): Folder {
        $segments = array_values(array_filter(explode('/', $path)));
        array_pop($segments);
        $parent = NodeAccess::run(fn () => NodeAccess::get($root, '/' . implode('/', $segments)));
        if (!$parent instanceof Folder || !$parent->isCreatable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
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
    private function confirmBoth(
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
    private function versions(Node $node, string $userId): ?int {
        return $node instanceof File ? $this->report->versions($node, $this->user($userId)) : null;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path user-relative path
     * @return string the absolute path in the user's own storage, which is what Node::move() takes
     */
    private function absolute(Folder $root, string $path): string {
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
