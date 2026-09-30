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
