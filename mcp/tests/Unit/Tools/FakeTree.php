<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

/**
 * In-memory file tree whose nodes are PHPUnit mocks of the OCP File/Folder interfaces.
 * Every mutating call is recorded in $ops so tests can prove what was (not) written.
 */
final class FakeTree {
    /** @var array<string, array{type:string, content?:string, mime?:string, id:int, mtime:int, etag:string, readable:bool, updateable:bool, deletable:bool}> */
    public array $nodes = [];
    /** @var list<string> */
    public array $ops = [];
    /** Paths whose copy() must fail, or produce a truncated copy. */
    public array $failCopy = [];
    public array $shortCopy = [];
    /** Display name of the owner returned for every node. */
    public string $ownerName = 'Alice';
    /** Display name of whoever shared a node whose scope is 'shared'. */
    public string $sharedByName = 'Pedro Almeida';
    /** UID IShare::getSharedBy() answers with; stable33 returns a string, not an IUser. */
    public string $sharedByUid = 'pedro';
    /** Absolute path of the mount a node lives in; null means "the node's own folder". */
    public ?string $mountPath = null;
    public bool $failWrite = false;
    /** @var list<\OCP\Files\Search\ISearchQuery> queries received by Folder::search */
    public array $searches = [];
    private int $nextId = 100;

    public function __construct(private TestCase $test, public string $root = '/alice/files') {
        $this->addFolder($root);
    }

    public function addFolder(string $path, array $flags = []): void {
        $this->nodes[$path] = ['type' => 'dir', 'id' => $this->nextId++, 'mtime' => 1759200000, 'etag' => 'e' . $this->nextId] + $flags + self::defaults();
    }

    public function addFile(string $path, string $content, string $mime = 'text/plain', array $flags = []): int {
        $parent = dirname($path);
        if (!isset($this->nodes[$parent])) {
            $this->addFolder($parent);
        }
        $id = $this->nextId++;
        $this->nodes[$path] = ['type' => 'file', 'content' => $content, 'mime' => $mime, 'id' => $id, 'mtime' => 1759200000 + $id, 'etag' => 'e' . $id] + $flags + self::defaults();
        return $id;
    }

    public function rootFolder(): Folder {
        return $this->node($this->root);
    }

    public function node(string $path): Node {
        if (!isset($this->nodes[$path])) {
            throw new NotFoundException();
        }
        return $this->nodes[$path]['type'] === 'dir' ? $this->folder($path) : $this->file($path);
    }

    private static function defaults(): array {
        return ['readable' => true, 'updateable' => true, 'deletable' => true];
    }

    private function mock(string $interface): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $interface);
    }

    private function common(object $mock, string $path): void {
        $mock->method('getPath')->willReturnCallback(fn () => $path);
        $mock->method('getName')->willReturn(basename($path));
        $mock->method('getId')->willReturnCallback(fn () => $this->nodes[$path]['id']);
        $mock->method('getMTime')->willReturnCallback(fn () => $this->nodes[$path]['mtime']);
        $mock->method('getEtag')->willReturnCallback(fn () => $this->nodes[$path]['etag'] ?? '');
        $mock->method('isReadable')->willReturnCallback(fn () => $this->nodes[$path]['readable']);
        $mock->method('isUpdateable')->willReturnCallback(fn () => $this->nodes[$path]['updateable']);
        $mock->method('isDeletable')->willReturnCallback(fn () => $this->nodes[$path]['deletable']);
        $mock->method('getStorage')->willReturnCallback(fn () => $this->storage($path));
        $mock->method('getInternalPath')->willReturn(ltrim($path, '/'));
        $mock->method('getMountPoint')->willReturnCallback(fn () => $this->mount($path));
        $mock->method('getOwner')->willReturnCallback(fn () => $this->owner($path));
        $mock->method('getPermissions')->willReturnCallback(fn () => $this->nodes[$path]['permissions'] ?? \OCP\Constants::PERMISSION_ALL);
        $mock->method('isShared')->willReturnCallback(fn () => ($this->nodes[$path]['scope'] ?? 'personal') === 'shared');
        $mock->method('getParent')->willReturnCallback(fn () => $this->node(dirname($path)));
        $mock->method('delete')->willReturnCallback(function () use ($path): void {
            $this->ops[] = "delete $path";
            unset($this->nodes[$path]);
        });
        $mock->method('move')->willReturnCallback(function (string $target) use ($path): Node {
            $this->ops[] = "move $path $target";
            $this->reparent($path, $target);
            return $this->node($target);
        });
        $mock->method('copy')->willReturnCallback(function (string $target) use ($path): Node {
            $this->ops[] = "copy $path $target";
            if (in_array($path, $this->failCopy, true)) {
                throw new NotPermittedException();
            }
            $this->duplicate($path, $target);
            return $this->node($target);
        });
    }

    /**
     * Nextcloud moves the whole subtree, and the ids of everything under it survive; only the entries move
     * from one parent to the other.
     */
    private function reparent(string $path, string $target): void {
        $children = $this->children($path);
        $this->nodes[$target] = $this->nodes[$path];
        unset($this->nodes[$path]);
        foreach ($children as $child) {
            $this->reparent($child, $target . '/' . basename($child));
        }
    }

    /** A copy duplicates the subtree, and every duplicated node gets an id of its own. */
    private function duplicate(string $path, string $target): void {
        $this->nodes[$target] = ['id' => $this->nextId++] + $this->nodes[$path];
        if ($this->nodes[$path]['type'] !== 'dir') {
            if (in_array($path, $this->shortCopy, true)) {
                $this->nodes[$target]['content'] = '';
            }
            return;
        }
        foreach ($this->children($path) as $child) {
            $this->duplicate($child, $target . '/' . basename($child));
        }
    }

    private function file(string $path): File {
        $file = $this->mock(File::class);
        $this->common($file, $path);
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getMimetype')->willReturnCallback(fn () => $this->nodes[$path]['mime']);
        $file->method('getSize')->willReturnCallback(fn () => $this->nodes[$path]['size'] ?? strlen($this->nodes[$path]['content']));
        $file->method('getContent')->willReturnCallback(fn () => $this->nodes[$path]['content']);
        $file->method('fopen')->willReturnCallback(function () use ($path) {
            $handle = fopen('php://memory', 'w+');
            fwrite($handle, $this->nodes[$path]['content']);
            rewind($handle);
            return $handle;
        });
        $file->method('putContent')->willReturnCallback(function (string $data) use ($path): void {
            $this->ops[] = "write $path";
            if ($this->failWrite) {
                throw new NotPermittedException();
            }
            $this->nodes[$path]['content'] = $data;
            $this->nodes[$path]['etag'] .= '+';
        });
        return $file;
    }

    private function folder(string $path): Folder {
        $folder = $this->mock(Folder::class);
        $this->common($folder, $path);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getMimetype')->willReturn('httpd/unix-directory');
        $folder->method('getSize')->willReturn(0);
        // Nextcloud decides isCreatable from the create permission on the node itself.
        $folder->method('isCreatable')->willReturnCallback(fn () => ($this->nodes[$path]['permissions'] ?? \OCP\Constants::PERMISSION_ALL)
            & \OCP\Constants::PERMISSION_CREATE);
        $folder->method('get')->willReturnCallback(fn (string $rel) => $this->node($path . '/' . ltrim($rel, '/')));
        $folder->method('nodeExists')->willReturnCallback(fn (string $rel) => isset($this->nodes[$path . '/' . ltrim($rel, '/')]));
        $folder->method('getDirectoryListing')->willReturnCallback(fn () => array_map(fn ($p) => $this->node($p), $this->children($path)));
        $folder->method('getRelativePath')->willReturnCallback(fn (string $abs) => $abs === $path ? '/' : (str_starts_with($abs, $path . '/') ? substr($abs, strlen($path)) : null));
        $folder->method('newFolder')->willReturnCallback(function (string $name) use ($path): Folder {
            $this->ops[] = "mkdir $path/$name";
            $this->addFolder("$path/$name");
            return $this->folder("$path/$name");
        });
        $folder->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($path): File {
            $this->ops[] = "create $path/$name";
            $this->addFile("$path/$name", (string)$content, 'text/markdown');
            return $this->file("$path/$name");
        });
        $folder->method('search')->willReturnCallback(function ($query) use ($path): array {
            $this->searches[] = $query;
            // Evaluates the escaped LIKE pattern like the database would, then applies the query limit.
            $regex = '/^' . preg_replace_callback('/\\\\(.)|%|_|[^%_\\\\]+/', static fn ($m) => match (true) {
                isset($m[1]) => preg_quote($m[1], '/'), $m[0] === '%' => '.*', $m[0] === '_' => '.', default => preg_quote($m[0], '/'),
            }, $query->getSearchOperation()->getValue()) . '$/iu';
            $matches = array_filter(array_keys($this->nodes), fn ($p) => str_starts_with($p, $path . '/') && preg_match($regex, basename($p)) === 1);
            return array_slice(array_values(array_map(fn ($p) => $this->node($p), $matches)), 0, $query->getLimit());
        });
        $folder->method('getById')->willReturnCallback(fn (int $id) => array_values(array_map(fn ($p) => $this->node($p),
            array_filter(array_keys($this->nodes), fn ($p) => str_starts_with($p, $path . '/') && $this->nodes[$p]['id'] === $id))));
        return $folder;
    }

    /**
     * The storage a node sits on. Home storage only when the node is personal, the trash wrapper still
     * decides whether a deletion is recoverable, and a shared node really is an ISharedStorage — which is
     * the only way the share branch of NodeAccessInfo gets exercised at all.
     */
    private function storage(string $path): object {
        $scope = $this->nodes[$path]['scope'] ?? 'personal';
        // A share mount jails the shared storage, so the object the node holds is a wrapper: it reports
        // itself as a shared storage through instanceOfStorage() and is not an ISharedStorage by instanceof.
        $storage = $this->mock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(function (string $class) use ($path, $scope): bool {
            if ($class === 'OCA\Files_Trashbin\Storage') {
                return $this->nodes[$path]['trash'] ?? true;
            }
            if ($class === \OCP\Files\Storage\ISharedStorage::class) {
                return $scope === 'shared';
            }
            return $class === \OCP\Files\IHomeStorage::class && $scope === 'personal';
        });
        // IStorage::getId() is what the move guard compares to keep a move inside one storage.
        $storage->method('getId')->willReturn($this->storageId($path));
        return $storage;
    }

    /**
     * The identifier IStorage::getId() reports, so a test can put two nodes on different storages.
     *
     * @param string $path node path
     * @return string the storage id, a single one for the whole tree unless a node says otherwise
     */
    public function storageId(string $path): string {
        return (string)($this->nodes[$path]['storageId'] ?? 'home');
    }

    /**
     * IShareManager double that answers with whatever shareOf() says for a node, so the wrapped-storage
     * path of NodeAccessInfo is exercised the way a real share mount would exercise it.
     *
     * @return \OCP\Share\IManager the manager
     */
    public function shareManager(): \OCP\Share\IManager {
        $manager = $this->mock(\OCP\Share\IManager::class);
        $manager->method('getSharesBy')->willReturnCallback(function (string $uid, int $type, ?Node $path = null): array {
            if ($type !== \OCP\Share\IShare::TYPE_USER || $path === null) {
                return [];
            }
            $share = $this->shareOf($path->getPath());
            return $share === null ? [] : [$share];
        });
        return $manager;
    }

    /**
     * The share a node belongs to, as IShare::getSharedBy() reports it in stable33: a UID string, and an
     * explicit null for a share with no sharer at all.
     *
     * @param string $path node path
     * @return \OCP\Share\IShare|null the share, when the node is shared
     */
    public function shareOf(string $path): ?\OCP\Share\IShare {
        if (($this->nodes[$path]['scope'] ?? 'personal') !== 'shared') {
            return null;
        }
        $share = $this->mock(\OCP\Share\IShare::class);
        $share->method('getSharedBy')->willReturn(array_key_exists('sharedBy', $this->nodes[$path])
            ? $this->nodes[$path]['sharedBy']
            : $this->sharedByUid);
        $share->method('getShareOwner')->willReturn($this->sharedByUid);
        return $share;
    }

    /** The mount point a node lives in: only the three shared mounts carry a mount type. */
    private function mount(string $path): object {
        $scope = $this->nodes[$path]['scope'] ?? 'personal';
        $mount = $this->mock(\OCP\Files\Mount\IMountPoint::class);
        $mount->method('getMountType')->willReturn(match ($scope) {
            'team' => 'group',
            'shared' => 'shared',
            'external' => 'external',
            default => '',
        });
        $mount->method('getMountPoint')->willReturn($this->mountPath ?? dirname($path));
        return $mount;
    }

    /** The owner of a node: the viewer for a personal file, the share owner otherwise. */
    private function owner(string $path): object {
        $scope = $this->nodes[$path]['scope'] ?? 'personal';
        $uid = $scope === 'personal' ? 'alice' : 'pedro';
        $user = $this->mock(\OCP\IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($scope === 'personal' ? $this->ownerName : $this->sharedByName);
        return $user;
    }

    /** @return list<string> */
    private function children(string $path): array {
        return array_values(array_filter(array_keys($this->nodes), fn ($p) => $p !== $path && dirname($p) === $path));
    }
}
