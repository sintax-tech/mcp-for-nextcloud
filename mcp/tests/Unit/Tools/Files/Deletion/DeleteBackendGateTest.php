<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Modules\Files\Deletion\DeleteBackendGate;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/LegacyBackendFixture.php';

/** Every observable gate refuses on an unknown or negative condition, independently of native mutation. */
final class DeleteBackendGateTest extends TestCase {
	/** @return iterable<string, array{string}> Narrow backend admission cases. */
	public static function cases(): iterable {
		foreach (['eligible', 'app_off', 'encryption', 'locking_off', 'part', 'skip', 'backend_error', 'backend_unsupported', 'quota', 'quota_invalid', 'read_error'] as $case) {
			yield $case => [$case];
		}
	}

	/**
	 * @param string $case Gate case, not a simulation of backend transfer correctness.
	 * @return void Known-ineligible and uncertain metadata refuse without any native delete.
	 */
	#[DataProvider('cases')]
	public function testPreventiveChecks(string $case): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($case !== 'app_off');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$encryption = $this->createMock(IManager::class);
		$encryption->method('isEnabled')->willReturn($case === 'encryption');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($case === 'skip' ? 'true' : '');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn($case !== 'locking_off');
		$config->method('getUserValue')->willReturn('-1');
		$appConfig = $this->createMock(IAppConfig::class);
		if ($case === 'read_error') {
			$appConfig->method('getValueString')->willThrowException(new \RuntimeException('secret native details'));
		} else {
			$appConfig->method('getValueString')->willReturn($case === 'quota' ? '1' : ($case === 'quota_invalid' ? 'invalid' : '-1'));
		}
		$container = $this->createMock(ContainerInterface::class);
		if ($case === 'backend_error') {
			$container->method('get')->willThrowException(new \RuntimeException('secret backend details'));
		} else {
			$backend = $case === 'backend_unsupported' ? new \stdClass() : new \OCA\Files_Trashbin\Trash\LegacyTrashBackend();
			$manager = new class($backend) {
				/** @param object $backend Unit-only backend identity. */
				public function __construct(private object $backend) {
				}
				/** @param IStorage $storage Synthetic storage. @return object Selected metadata backend. */
				public function getBackendForStorage(IStorage $storage): object {
					return $this->backend;
				}
			};
			$container->method('get')->willReturn($manager);
		}
		$file = $this->createMock(File::class);
		$file->expects(self::never())->method('delete');
		$file->method('getName')->willReturn($case === 'part' ? 'a.part' : 'a.txt');
		$file->method('getSize')->willReturn(20);
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$file->method('getOwner')->willReturn($owner);
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(true);
		$file->method('getStorage')->willReturn($storage);
		$gate = new DeleteBackendGate($apps, $users, $encryption, $request, $appConfig, $config, $this->createMock(ILockingProvider::class), $container);
		if ($case !== 'eligible') {
			$this->expectException(ToolFailure::class);
			$this->expectExceptionMessage('Nothing was deleted by this call.');
		}
		$gate->check($file, 'alice');
		self::assertSame('eligible', $case);
	}
}
