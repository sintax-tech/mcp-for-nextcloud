<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\Backups\BackupSettingsDocument;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Tools\Files\Deletion\DeleteBackendGate;
use OCA\Mcp\Tools\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\Share\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Eligibility denies before backend/sink; production backend behavior is independently covered in real NC. */
final class DeleteEligibilityTest extends TestCase {
	/** @return iterable<string, array{string}> Refused policies and structural states. */
	public static function refusals(): iterable {
		foreach (['etag', 'id', 'permission', 'owner', 'external', 'backup', 'hidden', 'folder', 'backup_unreadable'] as $case) {
			yield $case => [$case];
		}
	}

	/**
	 * @param string $case One eligibility refusal.
	 * @return void No backend effect can be reached, and hidden errors contain no native metadata.
	 */
	#[DataProvider('refusals')]
	public function testRefusedBeforeAnyBackend(string $case): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($case === 'owner' ? null : $user);
		$file->method('isReadable')->willReturn(true);
		$file->method('isDeletable')->willReturn($case !== 'permission');
		$file->method('getEtag')->willReturn('current');
		$file->method('getId')->willReturn(22);
		$file->method('getPath')->willReturn('/alice/files/private.txt');
		$file->method('isShared')->willReturn($case === 'shared');
		$file->expects(self::never())->method('delete');
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn ($class): bool => $case !== 'external' && in_array($class, [IHomeStorage::class, 'OC\\Files\\Storage\\Local'], true));
		$file->method('getStorage')->willReturn($storage);
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountPoint')->willReturn('/alice/');
		$file->method('getMountPoint')->willReturn($mount);
		$root = $this->createMock(Folder::class);
		$root->method('get')->willReturn($case === 'folder' ? $root : $file);
		$root->method('isReadable')->willReturn(true);
		$root->method('getPath')->willReturn('/alice/files');
		$root->method('getId')->willReturn(11);
		$root->method('getOwner')->willReturn($user);
		$file->method('getParent')->willReturn($root);
		$guard = $this->createMock(FilesAccessGuard::class);
		if ($case === 'hidden') {
			$guard->method('assertVisible')->willThrowException(new ToolFailure('Resource not found in Nextcloud.'));
		}
		$document = $this->createMock(BackupSettingsDocument::class);
		if ($case === 'backup_unreadable') {
			$document->method('read')->willThrowException(new \RuntimeException('private config contents'));
		}
		$shares = $this->createMock(IManager::class);
		$shares->method('getAccessList')->willReturn(['users' => ['alice'], 'remote' => false, 'public' => false, 'mail' => []]);
		$shares->method('getSharesBy')->willReturn($case === 'ancestor_shared' ? [$this->createMock(\OCP\Share\IShare::class)] : []);
		$backend = $this->createMock(DeleteBackendGate::class);
		$backend->expects(self::never())->method('check');
		$eligibility = new DeleteEligibility($guard, new BackupLocation($document), $backend, $shares);
		try {
			$eligibility->file($root, 'alice', $case === 'backup' ? '/MCP backups/private.txt' : '/private.txt', $case === 'etag' ? 'old' : null, $case === 'id' ? 999 : null);
			self::fail('Refusal expected: ' . $case);
		} catch (ToolFailure $error) {
			self::assertStringNotContainsString('private config contents', $error->getMessage());
			self::assertStringNotContainsString('private.txt', $error->getMessage());
		}
	}
}
