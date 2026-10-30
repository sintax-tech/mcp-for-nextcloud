<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\Share\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The personal home of alice as files_delete admits it: every node lives on the Local home storage, owned by alice,
 * below one mount at `/alice/`, and nothing is shared. Node::delete() stands for the native call: it records itself in
 * {@see self::$calls}, takes the node and its subtree out of the tree and, when the node's storage carries the trash
 * wrapper (`OCA\Files_Trashbin\Storage`, on by default), files one entry in {@see self::$trash}, as the trash wrapper
 * does for the deleted root. A node added with `trash: false` sits on a storage without that wrapper, where a native
 * delete would be permanent.
 */
final class TrashHomeTree {
	/** Absolute path of alice's root folder. */
	public const ROOT = '/alice/files';

	/** @var array<string, array{type:string, id:int, etag:string, content:string, trash:bool}> nodes by absolute path */
	public array $nodes = [];
	/** @var list<array{id:int, path:string, type:string, nodes:int}> what the trash wrapper received, in order */
	public array $trash = [];
	/** @var list<string> native mutations, in order: `delete <absolute path>` */
	public array $calls = [];
	/** Id the next node gets: ids are sequential, so two runs build the same tree. */
	private int $nextId = 300;

	/** @param TestCase $test the running test, owner of the mocks */
	public function __construct(private TestCase $test) {
		$this->nodes[self::ROOT] = ['type' => 'dir', 'id' => $this->nextId++, 'etag' => 'root', 'content' => '', 'trash' => true];
	}

	/**
	 * @param string $path absolute path below {@see self::ROOT}; missing parents are created
	 * @param bool $trash whether its storage carries the trash wrapper
	 * @return int the id of the new folder
	 */
	public function addFolder(string $path, bool $trash = true): int {
		return $this->add($path, 'dir', '', $trash);
	}

	/**
	 * @param string $path absolute path below {@see self::ROOT}; missing parents are created
	 * @param string $content bytes of the file
	 * @param bool $trash whether its storage carries the trash wrapper
	 * @return int the id of the new file
	 */
	public function addFile(string $path, string $content, bool $trash = true): int {
		return $this->add($path, 'file', $content, $trash);
	}

	/**
	 * Another client writes the node: its ETag changes, as after any write.
	 *
	 * @param string $path absolute path of an existing node
	 * @return void
	 */
	public function touch(string $path): void {
		$this->nodes[$path]['etag'] .= '+';
	}

	/** @return Folder alice's root folder */
	public function rootFolder(): Folder {
		$folder = $this->node(self::ROOT);
		assert($folder instanceof Folder);
		return $folder;
	}

	/** @return IRootFolder the filesystem root above alice's folder: id 0, no parent, alice's folder as user folder */
	public function filesystemRoot(): IRootFolder {
		$root = $this->mock(IRootFolder::class);
		$root->method('getId')->willReturn(0);
		$root->method('getPath')->willReturn('/');
		$root->method('getUserFolder')->willReturnCallback(fn (): Folder => $this->rootFolder());
		$root->method('getParent')->willThrowException(new NotFoundException());
		return $root;
	}

	/** @return IManager share metadata of a home where nothing is shared: alice is the only one with access */
	public function shareManager(): IManager {
		$shares = $this->mock(IManager::class);
		$shares->method('getAccessList')->willReturn(['users' => ['alice'], 'remote' => false, 'public' => false, 'mail' => []]);
		$shares->method('getSharesBy')->willReturn([]);
		$shares->method('getSharedWith')->willReturn([]);
		return $shares;
	}

	/** @return array<string, int> id of every node by its path relative to alice's root */
	public function view(): array {
		$view = [];
		foreach ($this->nodes as $path => $node) {
			$view[substr($path, strlen(self::ROOT)) ?: '/'] = $node['id'];
		}
		ksort($view, SORT_STRING);
		return $view;
	}

	/**
	 * @param string $path absolute path
	 * @return Node the node at that path
	 * @throws NotFoundException when nothing is there
	 */
	public function node(string $path): Node {
		if (!isset($this->nodes[$path])) {
			throw new NotFoundException();
		}
		$node = $this->mock($this->nodes[$path]['type'] === 'dir' ? Folder::class : File::class);
		$this->common($node, $path);
		if ($node instanceof Folder) {
			$this->folder($node, $path);
		}
		return $node;
	}

	/**
	 * @param string $path absolute path
	 * @param string $type `dir` or `file`
	 * @param string $content bytes of a file
	 * @param bool $trash whether its storage carries the trash wrapper
	 * @return int the id of the new node
	 */
	private function add(string $path, string $type, string $content, bool $trash): int {
		if (!isset($this->nodes[dirname($path)])) {
			$this->addFolder(dirname($path), $trash);
		}
		$id = $this->nextId++;
		$this->nodes[$path] = ['type' => $type, 'id' => $id, 'etag' => 'e' . $id, 'content' => $content, 'trash' => $trash];
		return $id;
	}

	/**
	 * @param MockObject $node a File or Folder mock
	 * @param string $path its absolute path
	 * @return void
	 */
	private function common(MockObject $node, string $path): void {
		$folder = $this->nodes[$path]['type'] === 'dir';
		$node->method('getPath')->willReturn($path);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getId')->willReturn($this->nodes[$path]['id']);
		$node->method('getEtag')->willReturnCallback(fn (): string => $this->nodes[$path]['etag'] ?? '');
		$node->method('getMTime')->willReturn(1790000000);
		$node->method('getType')->willReturn($folder ? FileInfo::TYPE_FOLDER : FileInfo::TYPE_FILE);
		$node->method('getMimetype')->willReturn($folder ? 'httpd/unix-directory' : 'text/plain');
		$node->method('getSize')->willReturnCallback(fn (): int => strlen($this->nodes[$path]['content'] ?? ''));
		$node->method('getPermissions')->willReturn(\OCP\Constants::PERMISSION_ALL);
		foreach (['isReadable', 'isUpdateable', 'isDeletable', 'isShareable'] as $method) {
			$node->method($method)->willReturn(true);
		}
		$node->method('getOwner')->willReturnCallback(fn (): IUser => $this->owner());
		$node->method('getStorage')->willReturnCallback(fn (): IStorage => $this->storage($this->nodes[$path]['trash'] ?? true));
		$node->method('getMountPoint')->willReturnCallback(fn (): IMountPoint => $this->mount());
		$node->method('getInternalPath')->willReturn(ltrim(substr($path, strlen('/alice')), '/'));
		$node->method('getParent')->willReturnCallback(fn (): Node => $path === self::ROOT ? $this->filesystemRoot() : $this->node(dirname($path)));
		$node->method('delete')->willReturnCallback(function () use ($path): void {
			$this->calls[] = 'delete ' . $path;
			$removed = array_filter(array_keys($this->nodes), static fn (string $p): bool => $p === $path || str_starts_with($p, $path . '/'));
			if ($this->nodes[$path]['trash']) {
				$this->trash[] = ['id' => $this->nodes[$path]['id'], 'path' => substr($path, strlen(self::ROOT)),
					'type' => $this->nodes[$path]['type'], 'nodes' => count($removed)];
			}
			foreach ($removed as $gone) {
				unset($this->nodes[$gone]);
			}
		});
	}

	/**
	 * @param MockObject $folder a Folder mock
	 * @param string $path its absolute path
	 * @return void
	 */
	private function folder(MockObject $folder, string $path): void {
		$below = static fn (string $rel): string => rtrim(rtrim($path, '/') . '/' . ltrim($rel, '/'), '/');
		$children = fn (): array => array_filter(array_keys($this->nodes), static fn (string $p): bool => $p !== $path && dirname($p) === $path);
		$byId = fn (int $id): array => array_values(array_map(fn (string $p): Node => $this->node($p), array_filter(array_keys($this->nodes),
			fn (string $p): bool => str_starts_with($p, $path . '/') && $this->nodes[$p]['id'] === $id)));
		$folder->method('get')->willReturnCallback(fn (string $rel): Node => $this->node($below($rel)));
		$folder->method('nodeExists')->willReturnCallback(fn (string $rel): bool => isset($this->nodes[$below($rel)]));
		$folder->method('isCreatable')->willReturn(true);
		$folder->method('getDirectoryListing')->willReturnCallback(fn (): array => array_values(array_map(fn (string $p): Node => $this->node($p),
			$children())));
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $abs): ?string => $abs === $path ? '/'
			: (str_starts_with($abs, $path . '/') ? substr($abs, strlen($path)) : null));
		$folder->method('getById')->willReturnCallback($byId);
		$folder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?Node => $byId($id)[0] ?? null);
	}

	/**
	 * @param bool $trash whether the trash wrapper is on this storage
	 * @return IStorage the Local home storage of alice
	 */
	private function storage(bool $trash): IStorage {
		$storage = $this->mock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class): bool => match ($class) {
			IHomeStorage::class, 'OC\\Files\\Storage\\Local' => true,
			'OCA\\Files_Trashbin\\Storage' => $trash,
			default => false,
		});
		$storage->method('getId')->willReturn('home::alice');
		return $storage;
	}

	/** @return IMountPoint the home mount of alice, at `/alice/`, of no special type */
	private function mount(): IMountPoint {
		$mount = $this->mock(IMountPoint::class);
		$mount->method('getMountPoint')->willReturn('/alice/');
		$mount->method('getMountType')->willReturn('');
		return $mount;
	}

	/** @return IUser alice, the owner of every node */
	private function owner(): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		return $user;
	}

	/**
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private function mock(string $class): MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
