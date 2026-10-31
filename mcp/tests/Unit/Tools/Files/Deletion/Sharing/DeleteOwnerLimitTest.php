<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Sharing;

use OCA\Mcp\Tools\Files\Deletion\DeleteBackendGate;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LegacyBackendFixture.php';

/** The native effective owner's explicit limit cannot be replaced by the recipient's allowance. */
final class DeleteOwnerLimitTest extends TestCase {
	/** @return void Alice's explicit limit refuses before any sink even though Bob's allowance is unlimited. */
	public function testNativeOwnerLimitIsAuthoritative(): void {
		$owner = $this->createMock(IUser::class); $owner->method('getUID')->willReturn('alice');
		$actor = $this->createMock(IUser::class); $actor->method('getUID')->willReturn('bob');
		$users = $this->createMock(\OCP\IUserManager::class); $users->method('get')->willReturnCallback(static fn ($uid) => $uid === 'alice' ? $owner : $actor);
		$apps = $this->createMock(\OCP\App\IAppManager::class); $apps->method('isEnabledForUser')->willReturn(true);
		$config = $this->createMock(\OCP\IConfig::class); $config->method('getSystemValueBool')->willReturn(true);
		$config->expects(self::once())->method('getUserValue')->with('alice', 'files_trashbin', 'trashbin_size', '-1')->willReturn('1');
		$file = $this->createMock(\OCP\Files\File::class); $file->method('getOwner')->willReturn($owner);
		$file->method('getName')->willReturn('a.txt'); $file->method('getSize')->willReturn(20); $file->expects(self::never())->method('delete');
		$storage = $this->createMock(\OCP\Files\Storage\IStorage::class); $storage->method('instanceOfStorage')->willReturn(true); $file->method('getStorage')->willReturn($storage);
		$manager = new class {
			/** @param object $storage Synthetic storage. @return object Known native backend identity. */
			public function getBackendForStorage(object $storage): object { return new \OCA\Files_Trashbin\Trash\LegacyTrashBackend(); }
		};
		$container = $this->createMock(\Psr\Container\ContainerInterface::class); $container->method('get')->willReturn($manager);
		$gate = new DeleteBackendGate($apps, $users, $this->createMock(\OCP\Encryption\IManager::class), $this->createMock(\OCP\IRequest::class),
			$this->createMock(\OCP\IAppConfig::class), $config, $this->createMock(\OCP\Lock\ILockingProvider::class), $container);
		$this->expectException(ToolFailure::class);
		$gate->check($file, 'bob');
	}
}
