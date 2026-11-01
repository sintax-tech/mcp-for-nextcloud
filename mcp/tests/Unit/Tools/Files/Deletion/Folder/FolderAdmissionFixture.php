<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Folder;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\Backups\BackupSettingsDocument;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Service\Scope\FilesScope;
use OCA\Mcp\Tools\Files\Deletion\DeleteBackendGate;
use OCA\Mcp\Tools\Files\Deletion\DeleteNodeAdmission;
use OCA\Mcp\Tools\Files\Deletion\Folder\DeleteFolderAdmission;
use OCA\Mcp\Tools\Files\Deletion\Folder\DeleteFolderBoundary;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

/** Metadata-only own-Local tree and counters; all share API calls remain independently visible. */
final class FolderAdmissionFixture {
	/** @var array<string,Node> Mock nodes keyed by absolute path. */
	public array $nodes = [];
	/** @var list<array{string,bool}> All-provider calls with the actual recursion flag. */
	public array $audiences = [];
	/** @var list<array{string,int}> Exact-node/type native share calls. */
	public array $shareCalls = [];
	/** @var array<string,string> Deliberate node state changes between inspections. */
	public array $flags = [];
	/** @var FilesAccessGuard Scope/exclusion collaborator. */
	public FilesAccessGuard $guard;
	/** @var DeleteFolderBoundary Bounded walk. */
	public DeleteFolderBoundary $boundary;
	/** @var DeleteFolderAdmission Fresh source/descendant admission. */
	public DeleteFolderAdmission $admission;

	/**
	 * @param TestCase $test Owner of PHPUnit native-interface mocks.
	 * @param array<string,bool> Absolute node paths and whether each is a folder; parents precede children.
	 */
	public function __construct(TestCase $test, array $paths) {
		$mock = static fn ($class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$user = $mock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$mount = $mock(IMountPoint::class);
		$mount->method('getMountPoint')->willReturn('/alice/');
		foreach ($paths as $path => $folder) {
			$node = $mock($folder ? Folder::class : File::class);
			$node->method('getPath')->willReturn($path);
			$node->method('getId')->willReturn(count($this->nodes) + 11);
			$node->method('getEtag')->willReturn('known');
			$node->method('getSize')->willReturn(1);
			$node->method('isReadable')->willReturn(true);
			$node->method('isDeletable')->willReturnCallback(fn (): bool => ($this->flags[$path] ?? '') !== 'deny');
			$node->method('isShared')->willReturnCallback(fn (): bool => ($this->flags[$path] ?? '') === 'shared');
			$node->method('isMounted')->willReturnCallback(fn (): bool => ($this->flags[$path] ?? '') === 'mount');
			$node->method('getOwner')->willReturn($user);
			$node->method('getMountPoint')->willReturn($mount);
			$node->method('getParent')->willReturnCallback(fn () => $this->nodes[dirname($path)]);
			$storage = $mock(IStorage::class);
			$storage->method('instanceOfStorage')->willReturnCallback(fn ($class): bool => ($this->flags[$path] ?? '') === 'received'
				? $class === \OCP\Files\Storage\ISharedStorage::class
				: in_array($class, [IHomeStorage::class, 'OC\\Files\\Storage\\Local'], true));
			$node->method('getStorage')->willReturn($storage);
			if ($folder) {
				$node->method('getDirectoryListing')->willReturnCallback(fn (): array => array_values(array_filter($this->nodes,
					static fn ($child): bool => dirname($child->getPath()) === $path && $child->getPath() !== $path)));
				$node->method('get')->willReturnCallback(fn ($relative) => $this->nodes[rtrim($path, '/') . '/' . ltrim($relative, '/')]);
				$node->method('getRelativePath')->willReturnCallback(static fn ($absolute): string => substr($absolute, strlen($path)) ?: '/');
			}
			$this->nodes[$path] = $node;
		}
		$this->guard = $mock(FilesAccessGuard::class);
		$this->guard->method('isConfigurationValid')->willReturn(true);
		$this->guard->method('assertVisible')->willReturnCallback(function ($node): void {
			if (($this->flags[$node->getPath()] ?? '') === 'hidden') { throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Resource not found'); }
		});
		$shares = $mock(IManager::class);
		$shares->method('getAccessList')->willReturnCallback(function ($node, $recursive, $current): array {
			$this->audiences[] = [$node->getPath(), $recursive];
			return ['users' => ['alice'], 'remote' => false, 'public' => ($this->flags[$node->getPath()] ?? '') === 'custom-provider', 'mail' => []];
		});
		$shares->method('getSharesBy')->willReturnCallback(function ($uid, $type, $node, $reshares, $limit, $offset, $valid): array {
			$this->shareCalls[] = [$node->getPath(), $type];
			\PHPUnit\Framework\Assert::assertFalse($valid, 'Inspection must not remove expired shares');
			return ($this->flags[$node->getPath()] ?? '') === 'native-share' ? [new \stdClass()] : [];
		});
		$this->boundary = new DeleteFolderBoundary($this->guard, $mock(FilesScope::class));
		$this->admission = new DeleteFolderAdmission($this->guard, new DeleteNodeAdmission($this->guard,
			new BackupLocation($mock(BackupSettingsDocument::class)), $mock(DeleteBackendGate::class), $shares));
	}

	/** @return array<string,mixed> One new complete inspection; no counters or approval are reused by production. */
	public function inspect(): array {
		return $this->admission->inspect($this->boundary, $this->nodes['/alice/files'], 'alice', $this->nodes['/alice/files/outer/source']);
	}
}
