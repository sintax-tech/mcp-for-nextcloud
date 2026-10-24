<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupCopies;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\BackupPlan;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real copy preparation, contact backup exemption, policy failures and historical mirror privacy. */
final class BackupOperationsTest extends FilesToolsTestCase {
	/**
	 * @param string $folder destination
	 * @param bool $copies configured Files preference
	 * @return array{BackupLocation, BackupCopies, FileBackup} live collaborators sharing fixture configuration
	 */
	private function settings(string $folder, bool $copies): array {
		$this->config->app['mcp'][BackupLocation::KEY] = json_encode(['version' => 1, 'folder' => $folder, 'reserved' => ['MCP backups', '.mcp-backups'], 'copies' => $copies]);
		$location = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $this->config->appConfig($this)));
		$policy = new BackupCopies($location, $this->apps, $this->users);
		$backup = new FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this), backupLocation: $location, backupCopies: $policy);
		return [$location, $policy, $backup];
	}

	/** @return iterable<string, array{bool}> effective copy preference */
	public static function copyPreferences(): iterable {
		yield 'copies on' => [true];
		yield 'copies suspended' => [false];
	}

	/**
	 * @param bool $copies administrator preference while native versioning is active
	 * @return void
	 */
	#[DataProvider('copyPreferences')]
	public function testPreparationAndPlanUseTheConfiguredDotFolder(bool $copies): void {
		[$location, $policy, $backup] = $this->settings('.mcp-backups', $copies);
		$file = $this->tree->node('/alice/files/Documentos/ata.md');
		$before = $file->getContent();
		$path = $backup->prepare($this->tree->rootFolder(), $file, '/Documentos/ata.md', 'alice', null);
		if ($copies) {
			self::assertStringStartsWith('/.mcp-backups/Documentos/', $path);
			self::assertSame($before, $this->tree->node('/alice/files' . $path)->getContent());
		} else {
			self::assertSame('', $path);
			self::assertFalse($this->tree->rootFolder()->nodeExists('.mcp-backups'));
		}
		self::assertSame($before, $file->getContent());
		$plan = (new BackupPlan($location, $policy))->decorate(['message' => 'default plan', 'recoverable' => true], 'alice');
		self::assertSame($copies ? '/.mcp-backups' : '', $plan['backup']);
		if (!$copies) {
			self::assertStringContainsString('no MCP pre-write copy', $plan['message']);
		}
	}

	/** @return void app disablement does not turn a previously forbidden operation into an allowed edit */
	public function testDisabledVersioningKeepsHistoricalRefusalAndEffectiveCopiesOn(): void {
		[, $policy, $backup] = $this->settings('.mcp-backups', false);
		$this->enabled = [];
		self::assertTrue($policy->required('alice'));
		$this->expectException(ToolFailure::class);
		$backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'), '/Documentos/ata.md', 'alice', null);
	}

	/** @return void disabled retention means no automatic expiration; it is not disabled version capture */
	public function testDisabledExpirationDoesNotForceCopiesWhenVersionsIsEnabled(): void {
		$this->config->system['versions_retention_obligation'] = 'disabled';
		[, $policy, $backup] = $this->settings('.mcp-backups', false);
		self::assertFalse($policy->required('alice'));
		self::assertSame('', $backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'), '/Documentos/ata.md', 'alice', null));
	}

	/** @return void contact deletion keeps a verified full copy even while Files copies are suspended */
	public function testContactCopyRemainsMandatoryInConfiguredFolder(): void {
		[$location] = $this->settings('.mcp-backups', false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable('2026-10-24T12:00:00Z'));
		$backup = new ContactBackup($this->visibilityRoot(), $time, $this->config->mock($this), $this->createMock(ILockingProvider::class), $location);
		$card = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Disposable\r\nUID:bk2\r\nEND:VCARD\r\n";
		$path = $backup->save('alice', 'Book', 'Disposable', $card);
		self::assertStringStartsWith('/.mcp-backups/Contacts/Book/', $path);
		self::assertSame($card, $this->tree->node('/alice/files' . $path)->getContent());
	}

	/** @return void all historical root names inherit original exclusions, even with the Files switch off */
	public function testEveryHistoricalMirrorRemainsHiddenAndProtected(): void {
		[$location] = $this->settings('.mcp-backups', false);
		$original = $this->tree->addFile('/alice/files/secret.txt', 'private');
		$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
		$mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => in_array((string)$original, $ids, true) ? [(string)$original => ['42']] : []);
		$guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->visibilityRoot(), backupLocation: $location);
		foreach (['MCP backups', '.mcp-backups'] as $folder) {
			$path = '/alice/files/' . $folder . '/secret.txt.20261024-120000.bak';
			$this->tree->addFile($path, 'private');
			self::assertTrue(FileBackup::isBackupPath('/' . $folder . '/x', $location));
			self::assertFalse($guard->isVisible($this->tree->node($path)));
		}
	}
	/** @return void a configured reservation is enforced through the extracted copy handler, including plans */
	public function testCopyCannotUseAnyReservedDestination(): void {
		[$location] = $this->settings('.mcp-backups', false);
		$access = new \OCA\Mcp\Tools\Common\NodeAccessInfo($this->users, $this->tree->shareManager());
		$service = new \OCA\Mcp\Tools\Files\Reorganization($access, new \OCA\Mcp\Tools\Common\SharedWriteGuard($access), $this->report(), $this->users, backupLocation: $location);
		foreach (['MCP backups', '.mcp-backups'] as $folder) {
			foreach ([false, true] as $write) {
				try {
					$write ? $service->copy($this->tree->rootFolder(), 'alice', '/Documentos/ata.md', '/' . $folder, null, false)
						: $service->planCopy($this->tree->rootFolder(), 'alice', '/Documentos/ata.md', '/' . $folder, null);
					self::fail('Copy destination reservation bypassed');
				} catch (ToolFailure $error) {
					self::assertSame(\OCA\Mcp\Tools\Files\FilesMessages::backupPath(), $error->getMessage());
				}
			}
		}
	}

	/** @return void actual file-copy preparation and mandatory contact copies refuse malformed reservation metadata */
	public function testCorruptionRefusesFileAndContactCopiesWithoutWriting(): void {
		foreach (['{invalid', '{}', '{"version":1,"folder":".mcp-backups","reserved":[],"copies":false}', 'read-error'] as $raw) {
			[$location, , $backup] = $this->settings('.mcp-backups', true);
			$this->config->app['mcp'][BackupLocation::KEY] = $raw;
			if ($raw === 'read-error') {
				$config = $this->createMock(\OCP\IAppConfig::class);
				$config->method('hasKey')->willReturn(true);
				$config->method('getValueString')->willThrowException(new \RuntimeException('private backend'));
				$location = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $config));
				$backup = new FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this), backupLocation: $location);
			}
			$contact = new ContactBackup($this->visibilityRoot(), $this->time, $this->config->mock($this), $this->createMock(ILockingProvider::class), $location);
			foreach ([fn () => $backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'), '/Documentos/ata.md', 'alice', null), fn () => $contact->save('alice', 'Book', 'Name', 'private')] as $operation) {
				try { $operation(); self::fail('Copy bypassed corrupt settings'); } catch (ToolFailure) { }
			}
			self::assertFalse($this->tree->rootFolder()->nodeExists('.mcp-backups'));
		}
	}

	/** @return void OFF plans preserve each tool's concrete operation without an unconditional copy promise */
	public function testSuspendedPlansRetainCheckoutAndRestoreInstructions(): void {
		[$location, $copies] = $this->settings('.mcp-backups', false);
		foreach (['files_checkout' => 'download and upload links', 'files_version_restore' => 'selected version', 'files_edit' => 'content below', 'files_replace' => 'snippet below'] as $action => $instruction) {
			$plan = (new BackupPlan($location, $copies))->decorate(['action' => $action, 'message' => 'a copy is kept'], 'alice');
			self::assertStringContainsString($instruction, $plan['message']);
			self::assertStringContainsString('No MCP pre-write copy', $plan['message']);
			self::assertStringNotContainsString('a copy is kept', $plan['message']);
			self::assertSame('', $plan['backup']);
		}
	}

	/** @return void only a late reservation refusal carries a single truthful copy note; unrelated failures escape unchanged */
	public function testLateReservationRefusalReportsRecoveryTruthfully(): void {
		foreach (['/.mcp-backups/Documentos/a.bak', ''] as $copy) {
			$backup = $this->createMock(FileBackup::class);
			$backup->method('assertWritablePath')->willThrowException(new ToolFailure('Reserved path'));
			try { \OCA\Mcp\Tools\Files\BackupWriteBoundary::afterCopy($backup, '/a', $copy); self::fail('Late refusal bypassed'); }
			catch (ToolFailure $error) {
				self::assertSame('Reserved path ' . \OCA\Mcp\Tools\Files\FilesMessages::originalKept($copy), $error->getMessage());
				if ($copy !== '') { self::assertSame(1, substr_count($error->getMessage(), $copy)); }
				else { self::assertStringNotContainsString('original is kept', $error->getMessage()); }
			}
		}
		$backup = $this->createMock(FileBackup::class);
		$backup->method('assertWritablePath')->willThrowException(new \RuntimeException('unrelated'));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('unrelated');
		\OCA\Mcp\Tools\Files\BackupWriteBoundary::afterCopy($backup, '/a', '/copy');
	}

	/** @return void many sibling decisions share one config load; guard invalidation reloads and corruption hides with per-node safe logging */
	public function testMatcherSharesSnapshotsNormalizesNamesAndInvalidates(): void {
		$loads = 0;
		$raw = json_encode(['version' => 1, 'folder' => "Co\u{0301}pias MCP", 'reserved' => ['MCP backups', "Co\u{0301}pias MCP"], 'copies' => true]);
		// A mutable reference is intentional: another process can replace the document between decisions.
		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->expects(self::never())->method('clearCache');
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturnCallback(function () use (&$raw, &$loads): string { $loads++; return $raw; });
		$location = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $config));
		$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
		$id = $this->tree->addFile('/alice/files/secret.txt', 'private');
		$mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => in_array((string)$id, $ids, true) ? [(string)$id => ['42']] : []);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects(self::exactly(3))->method('warning')->with('MCP visibility check failed; access refused', ['app' => 'mcp']);
		$guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->visibilityRoot(), logger: $logger, backupLocation: $location);
		foreach (['Cópias MCP', "Co\u{0301}pias MCP"] as $folder) {
			$path = '/alice/files/' . $folder . '/secret.txt.20261024-120000.bak';
			$this->tree->addFile($path, 'private');
			self::assertFalse($guard->isVisible($this->tree->node($path)));
		}
		for ($i = 0; $i < 30; $i++) {
			$path = '/alice/files/siblings/' . $i . '.txt';
			$this->tree->addFile($path, 'public');
			self::assertTrue($guard->isVisible($this->tree->node($path)));
		}
		self::assertSame(1, $loads);
		$guard->clearCache();
		self::assertTrue($guard->isVisible($this->tree->node('/alice/files/siblings/0.txt')));
		self::assertSame(2, $loads);
		$raw = '{invalid';
		$guard->clearCache();
		for ($i = 0; $i < 3; $i++) {
			self::assertFalse($guard->isVisible($this->tree->node('/alice/files/siblings/' . $i . '.txt')));
		}
		self::assertSame(3, $loads);
	}

	/** @return void a different service's change between plan/copy is observed, and late restore refusal never reaches either native sink */
	public function testRestoreRefusalAfterCopyKeepsCopyLocationAndNeverWrites(): void {
		require_once __DIR__ . '/Stubs/versions_api.php';
		foreach ([true, false] as $createCopy) {
			[$location, $copies, $real] = $this->settings('.mcp-backups', $createCopy);
			$plan = (new BackupPlan($location, $copies))->decorate(['action' => 'files_version_restore'], 'alice');
			self::assertSame($createCopy ? '/.mcp-backups' : '', $plan['backup']);
			$copy = null;
			// Another instance saves a new destination after the plan; prepare must not reuse that plan snapshot.
			$this->config->app['mcp'][BackupLocation::KEY] = json_encode(['version' => 1, 'folder' => 'B', 'reserved' => ['MCP backups', '.mcp-backups', 'B'], 'copies' => $createCopy]);
			$other = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $this->config->appConfig($this)));
			self::assertSame('B', $other->current()['folder']);
			$backup = $this->getMockBuilder(FileBackup::class)->setConstructorArgs([$this->apps, $this->users, $this->time, $this->config->mock($this), null, null, $location, $copies])->onlyMethods(['prepare'])->getMock();
			$backup->method('prepare')->willReturnCallback(function ($root, $file, $path, $uid, $etag) use ($real, &$copy, $createCopy): string {
				$copy = $real->prepare($root, $file, $path, $uid, $etag);
				if ($createCopy) { self::assertStringStartsWith('/B/', $copy); }
				$this->config->app['mcp'][BackupLocation::KEY] = json_encode(['version' => 1, 'folder' => 'Documentos', 'reserved' => ['MCP backups', '.mcp-backups', 'B', 'Documentos'], 'copies' => $createCopy]);
				return $copy;
			});
			$manager = \OCA\Files_Versions\Versions\FakeVersionManager::with([]);
			$manager->versions = [(new \OCA\Files_Versions\Versions\FakeVersion('1', 1759100000, 3, 'text/plain', 'ata.md'))->withContent('old')];
			$container = $this->createMock(\Psr\Container\ContainerInterface::class);
			$container->method('get')->willReturn($manager);
			$access = new \OCA\Mcp\Tools\Common\NodeAccessInfo($this->users, $this->tree->shareManager());
			$restore = new \OCA\Mcp\Tools\Files\VersionRestore($this->apps, $this->users, new \OCA\Mcp\Tools\Files\ContentTypeGate(\OCA\Mcp\Tests\Unit\Tools\FileTypePolicyFixture::off($this)), $access, $container);
			$file = $this->tree->node('/alice/files/Documentos/ata.md');
			$before = $file->getContent();
			try { $restore->restore($this->tree->rootFolder(), $file, '/Documentos/ata.md', '1', 'alice', $backup); self::fail('Late reservation written'); }
			catch (ToolFailure $error) {
				self::assertSame(\OCA\Mcp\Tools\Files\FilesMessages::backupPath() . ' ' . \OCA\Mcp\Tools\Files\FilesMessages::originalKept($copy), $error->getMessage());
			}
			self::assertNotContains('rollback', $manager->ops);
			self::assertSame($before, $file->getContent());
			if ($createCopy) { self::assertSame($before, $this->tree->rootFolder()->get($copy)->getContent()); }
		}
	}

	/**
	 * @param bool $createCopy effective copy preference
	 * @return void checkout's late reservation refusal keeps HTTP 400 and one accurate recovery note before its sink
	 */
	#[DataProvider('copyPreferences')]
	public function testCheckoutRefusalAfterCopyReportsRecoveryWithoutWriting(bool $createCopy): void {
		[$location, $copies, $real] = $this->settings('.mcp-backups', $createCopy);
		$file = $this->tree->node('/alice/files/Documentos/ata.md');
		$before = $file->getContent();
		$file->expects(self::never())->method('putContent');
		$copy = '';
		$backup = $this->getMockBuilder(FileBackup::class)->setConstructorArgs([$this->apps, $this->users, $this->time, $this->config->mock($this), null, null, $location, $copies])->onlyMethods(['prepare'])->getMock();
		$backup->method('prepare')->willReturnCallback(function ($root, $node, $path, $uid, $etag) use ($real, &$copy, $createCopy): string {
			$copy = $real->prepare($root, $node, $path, $uid, $etag);
			$this->config->app['mcp'][BackupLocation::KEY] = json_encode(['version' => 1, 'folder' => 'Documentos', 'reserved' => ['MCP backups', '.mcp-backups', 'Documentos'], 'copies' => $createCopy]);
			return $copy;
		});
		$access = new \OCA\Mcp\Tools\Common\NodeAccessInfo($this->users, $this->tree->shareManager());
		$unused = static fn () => throw new \LogicException('Unexpected callback');
		$handler = new \OCA\Mcp\Tools\Files\Handler\CheckoutWriteHandler($this->visibilityRoot(), null, $backup,
			new \OCA\Mcp\Tools\Common\SharedWriteGuard($access), $access, new \Psr\Log\NullLogger(), null, null, null, null, \OCA\Mcp\Tests\Unit\Tools\FileTypePolicyFixture::off($this),
			$unused, $unused, $unused, static fn () => false, $unused, $unused,
			static fn (int $status, string $message) => new \OCP\AppFramework\Http\JSONResponse(['error' => $message], $status), $unused);
		$row = new \OCA\Mcp\Checkout\CheckoutToken(1, 'upload', 'alice', $file->getId(), '/Documentos/ata.md', $file->getEtag(), 'personal', true, PHP_INT_MAX, true);
		$response = $handler->write($row, $file, '/body-must-not-be-read');
		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => \OCA\Mcp\Tools\Files\FilesMessages::backupPath() . ' ' . \OCA\Mcp\Tools\Files\FilesMessages::originalKept($copy)], $response->getData());
		self::assertSame($before, $file->getContent());
		if ($createCopy) {
			self::assertNotSame('', $copy);
			self::assertSame($before, $this->tree->rootFolder()->get($copy)->getContent());
			self::assertSame(1, substr_count($response->getData()['error'], $copy));
		} else {
			self::assertSame('', $copy);
			self::assertFalse($this->tree->rootFolder()->nodeExists('.mcp-backups'));
		}
	}

}
