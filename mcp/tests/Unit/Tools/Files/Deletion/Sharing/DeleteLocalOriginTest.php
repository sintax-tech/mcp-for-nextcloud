<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Sharing;

use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupSettingsDocument;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\Files\Deletion\DeleteBackendGate;
use OCA\Mcp\Tools\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Tools\Files\Deletion\Sharing\DeleteLocalOrigin;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IHomeStorage;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

/** Owner metadata can only narrow admission; it never supplies the actor's DELETE permission. */
final class DeleteLocalOriginTest extends TestCase {
	/** @return void Received aliases resolve only a unique verified Local origin; roots, missing origins and backup aliases refuse. */
	public function testOriginReservationAndUnresolvedStatesFailClosed(): void {
		foreach (['valid', 'root', 'missing', 'backup', 'external'] as $case) {
			[$actor, $actorRoot, $ownerRoot, $roots, $backups] = $this->fixture($case);
			try {
				$result = (new DeleteLocalOrigin($backups, $roots))->resolve($actorRoot, 'bob', $actor);
				self::assertSame('valid', $case);
				self::assertSame('alice', $result['owner']);
				self::assertTrue($result['received']);
			} catch (\Throwable) { self::assertNotSame('valid', $case); }
		}
	}

	/** @return void Owner-native permissions are not borrowed: READ+DELETE without UPDATE on the actor admits, lack of DELETE refuses. */
	public function testActorDeleteIsAuthoritativeAndUpdateIsNeverRequired(): void {
		foreach ([true, false] as $deletable) {
			[$actor, $actorRoot, $ownerRoot, $roots, $backups] = $this->fixture('valid', $deletable);
			$guard = $this->createMock(FilesAccessGuard::class);
			$shares = $this->createMock(IManager::class);
			$shares->method('getAccessList')->willReturn(['users' => ['alice', 'bob'], 'remote' => false, 'public' => false, 'mail' => []]);
			$backend = $this->createMock(DeleteBackendGate::class);
			$backend->expects($deletable ? self::once() : self::never())->method('check');
			$eligibility = new DeleteEligibility($guard, $backups, $backend, $shares, null, $roots);
			try {
				self::assertSame($actor, $eligibility->file($actorRoot, 'bob', '/alias/a.txt'));
				self::assertTrue($deletable);
			} catch (ToolFailure) { self::assertFalse($deletable); }
		}
	}

	/**
	 * @param string $case Origin state.
	 * @param bool $deletable Actor's DELETE bit.
	 * @return array{File,Folder,Folder,IRootFolder,BackupLocation} Synthetic public API context.
	 */
	private function fixture(string $case, bool $deletable = true): array {
		$user = $this->createMock(IUser::class); $user->method('getUID')->willReturn('alice');
		$actor = $this->createMock(File::class); $origin = $this->createMock(File::class);
		$ownerRoot = $this->createMock(Folder::class); $actorRoot = $this->createMock(Folder::class);
		$ownStorage = $this->createMock(IStorage::class);
		$ownStorage->method('instanceOfStorage')->willReturnCallback(static fn ($class): bool => $case !== 'external' && in_array($class, [IHomeStorage::class, 'OC\\Files\\Storage\\Local'], true));
		$sharedStorage = $this->createMock(IStorage::class);
		$sharedStorage->method('instanceOfStorage')->willReturnCallback(static fn ($class): bool => $class === ISharedStorage::class);
		$mount = $this->createMock(IMountPoint::class); $mount->method('getMountPoint')->willReturn('/bob/files/alias/');
		$ownMount = $this->createMock(IMountPoint::class); $ownMount->method('getMountPoint')->willReturn('/alice/');
		$actor->method('getStorage')->willReturn($sharedStorage); $actor->method('getMountPoint')->willReturn($mount);
		$actor->method('getPath')->willReturn($case === 'root' ? '/bob/files/alias' : '/bob/files/alias/a.txt');
		$actor->method('getId')->willReturn(25); $actor->method('getOwner')->willReturn($user);
		$actor->method('getEtag')->willReturn('known'); $actor->method('isReadable')->willReturn(true);
		$actor->method('isDeletable')->willReturn($deletable); $actor->expects(self::never())->method('isUpdateable');
		$origin->method('getId')->willReturn(25); $origin->method('getOwner')->willReturn($user);
		$origin->method('getPath')->willReturn($case === 'backup' ? '/alice/files/MCP backups/a.txt' : '/alice/files/source/a.txt');
		$origin->method('getStorage')->willReturn($ownStorage); $origin->method('getMountPoint')->willReturn($ownMount);
		$origin->method('getParent')->willReturn($ownerRoot);
		$ownerRoot->method('getId')->willReturn(11); $ownerRoot->method('getPath')->willReturn('/alice/files');
		$ownerRoot->method('getOwner')->willReturn($user); $ownerRoot->method('getStorage')->willReturn($ownStorage);
		$ownerRoot->method('getMountPoint')->willReturn($ownMount);
		$ownerRoot->method('getById')->willReturn($case === 'missing' ? [] : [$origin]);
		$ownerRoot->method('getRelativePath')->willReturnCallback(static fn ($path): string => substr($path, strlen('/alice/files')));
		$actorRoot->method('get')->willReturn($actor); $actorRoot->method('getPath')->willReturn('/bob/files');
		$roots = $this->createMock(IRootFolder::class); $roots->method('getUserFolder')->with('alice')->willReturn($ownerRoot);
		return [$actor, $actorRoot, $ownerRoot, $roots, new BackupLocation($this->createMock(BackupSettingsDocument::class))];
	}
}
