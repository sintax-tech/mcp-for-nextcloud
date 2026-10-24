<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Service\BackupFolderMatcher;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Regression coverage for personal-root recognition and mounted backup candidates. */
final class BackupFolderMatcherTest extends TestCase {
	/**
	 * @return iterable<string, array{string, bool}> path prefix and expected backup classification
	 */
	public static function locations(): iterable {
		yield 'personal root' => ['', true];
		yield 'ordinary subfolder' => ['docs/', false];
		yield 'external mount interior' => ['external/dir/', false];
		yield 'received share interior' => ['received/', false];
	}

	/**
	 * A hidden sibling original must affect only the reserved folder at the personal root.
	 *
	 * @param string $prefix directory above the candidate folder
	 * @param bool $backup whether the mirror rule should apply
	 * @return void
	 */
	#[DataProvider('locations')]
	public function testOnlyPersonalRootMirrorsInheritOriginalExclusions(string $prefix, bool $backup): void {
		$tree = new FakeTree($this);
		$original = $tree->addFile('/alice/files/' . $prefix . 'secret.txt', 'private');
		$path = '/alice/files/' . $prefix . 'MCP backups/secret.txt.20261024-120000.bak';
		$tree->addFile($path, 'copy');
		$config = new InMemoryConfig();
		$config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => in_array((string)$original, $ids, true) ? [(string)$original => ['42']] : []);
		$guard = new VisibilityGuard($config->mock($this), $mapper, $tree->filesystemRoot());
		self::assertSame(!$backup, $guard->isVisible($tree->node($path)));
		self::assertSame($backup ? VisibilityGuard::EXPLAINED_BACKUP : VisibilityGuard::EXPLAINED_VISIBLE, $guard->explain($tree->node($path))['reason']);
	}

	/**
	 * @param int $id stable node id
	 * @param string $path mounted absolute path
	 * @param Node $parent mount-aware parent
	 * @param string|null $owner local owner uid, absent for an external storage
	 * @return Folder folder mock with the metadata used by the matcher
	 */
	private function folder(int $id, string $path, Node $parent, ?string $owner = 'alice'): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getPath')->willReturn($path);
		$folder->method('getParent')->willReturn($parent);
		if ($owner !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($owner);
			$folder->method('getOwner')->willReturn($user);
		}
		return $folder;
	}

	/**
	 * @return iterable<string, array{bool, bool}> owner location and recipient mount depth
	 */
	public static function shareLocations(): iterable {
		yield 'real backup at recipient root' => [true, false];
		yield 'ordinary folder at recipient root' => [false, false];
		yield 'real backup mounted below a folder' => [true, true];
		yield 'ordinary folder mounted below a folder' => [false, true];
	}

	/**
	 * Source ancestry decides whether a received named folder is the owner's actual backup root.
	 *
	 * @param bool $realBackup whether the source is directly under the owner's personal root
	 * @param bool $nested whether the recipient mounted it below another folder
	 * @return void
	 */
	#[DataProvider('shareLocations')]
	public function testReceivedShareUsesOwnerLocation(bool $realBackup, bool $nested): void {
		$root = $this->createMock(IRootFolder::class);
		$alice = $this->folder(1, '/alice/files', $root);
		$docs = $this->folder(2, '/alice/files/docs', $alice);
		$source = $this->folder(3, $realBackup ? '/alice/files/MCP backups' : '/alice/files/docs/MCP backups', $realBackup ? $alice : $docs);
		$alice->method('getById')->with(3)->willReturn([$source]);
		$bob = $this->folder(4, '/bob/files', $root, 'bob');
		$recipientParent = $nested ? $this->folder(5, '/bob/files/incoming', $bob, 'bob') : $bob;
		$mount = $this->folder(3, $recipientParent->getPath() . '/MCP backups', $recipientParent);
		$file = $this->folder(6, $mount->getPath() . '/secret.txt.20261024-120000.bak', $mount);
		$root->method('getUserFolder')->willReturnMap([['alice', $alice], ['bob', $bob]]);
		$matches = (new BackupFolderMatcher($root))->matches($file, static fn (Node $node): Node => $node->getParent());
		self::assertCount($realBackup ? 1 : 0, $matches);
		if ($realBackup) {
			self::assertSame($alice, $matches[0][0]);
			self::assertSame(['secret.txt.20261024-120000.bak'], $matches[0][1]);
		}
	}

	/**
	 * An ownerless external mount named like the backup folder follows its mounted personal root.
	 *
	 * @return void
	 */
	public function testOwnerlessExternalMountAtPersonalRootIsRecognized(): void {
		$root = $this->createMock(IRootFolder::class);
		$home = $this->folder(1, '/alice/files', $root);
		$mount = $this->folder(2, '/alice/files/MCP backups', $home, null);
		$file = $this->folder(3, $mount->getPath() . '/x', $mount, null);
		$root->method('getUserFolder')->with('alice')->willReturn($home);
		self::assertCount(1, (new BackupFolderMatcher($root))->matches($file, static fn (Node $node): Node => $node->getParent()));
	}

	/**
	 * A root-level external mount is allowed as the destination, but a nested namesake is ordinary.
	 *
	 * @return void
	 */
	public function testOwnerlessExternalMountInteriorIsNotABackup(): void {
		$root = $this->createMock(IRootFolder::class);
		$home = $this->folder(1, '/alice/files', $root);
		$mount = $this->folder(2, '/alice/files/external', $home, null);
		$nested = $this->folder(3, $mount->getPath() . '/MCP backups', $mount, null);
		$file = $this->folder(4, $nested->getPath() . '/x', $nested, null);
		$root->expects(self::never())->method('getUserFolder');
		self::assertSame([], (new BackupFolderMatcher($root))->matches($file, static fn (Node $node): Node => $node->getParent()));
	}

	/**
	 * A same-named nested directory inside a real backup must not truncate the mirrored original path.
	 *
	 * @return void
	 */
	public function testNestedNamesakeInsideRealBackupKeepsOuterMirrorPath(): void {
		$tree = new FakeTree($this);
		$path = '/alice/files/MCP backups/docs/MCP backups/x.20261024-120000.bak';
		$tree->addFile($path, 'x');
		$matches = (new BackupFolderMatcher())->matches($tree->node($path), static fn (Node $node): Node => $node->getParent());
		self::assertSame(['docs', 'MCP backups', 'x.20261024-120000.bak'], $matches[0][1]);
	}
	/**
	 * An unresolved owner candidate fails closed through the guard and keeps the inspector reason unknown.
	 *
	 * @return void
	 */
	public function testUnavailablePersonalRootIsRefused(): void {
		$root = $this->createMock(IRootFolder::class);
		$home = $this->folder(1, '/alice/files', $root);
		$backup = $this->folder(2, '/alice/files/MCP backups', $home);
		$file = $this->folder(3, $backup->getPath() . '/x.20261024-120000.bak', $backup);
		$root->method('getUserFolder')->willThrowException(new \RuntimeException('unavailable'));
		$config = new InMemoryConfig();
		$config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturn([]);
		$guard = new VisibilityGuard($config->mock($this), $mapper, $root);
		self::assertFalse($guard->isVisible($file));
		self::assertSame(VisibilityGuard::EXPLAINED_NOT_EVALUATED, $guard->explain($file)['reason']);
	}

	/**
	 * A received namesake with no resolvable owner origin is never silently classified as ordinary.
	 *
	 * @return void
	 */
	public function testUnavailableShareOriginIsRefused(): void {
		$root = $this->createMock(IRootFolder::class);
		$alice = $this->folder(1, '/alice/files', $root);
		$alice->method('getById')->willReturn([]);
		$bob = $this->folder(2, '/bob/files', $root, 'bob');
		$backup = $this->folder(3, '/bob/files/MCP backups', $bob);
		$file = $this->folder(4, $backup->getPath() . '/x', $backup);
		$root->method('getUserFolder')->with('alice')->willReturn($alice);
		$this->expectException(\RuntimeException::class);
		(new BackupFolderMatcher($root))->matches($file, static fn (Node $node): Node => $node->getParent());
	}

}
