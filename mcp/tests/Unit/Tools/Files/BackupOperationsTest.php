<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\Backups\BackupCopies;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\Backups\BackupPlan;
use OCA\Mcp\Modules\Contacts\Backup\ContactBackup;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
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
		$access = new \OCA\Mcp\Platform\FileAccess\NodeAccessInfo($this->users, $this->tree->shareManager());
		$service = \OCA\Mcp\Tests\Unit\Tools\Files\FilesCatalogWiring::reorganization($access, new \OCA\Mcp\Platform\FileAccess\SharedWriteGuard($access), $this->report(), $this->users, backupLocation: $location);
		foreach (['MCP backups', '.mcp-backups'] as $folder) {
			foreach ([false, true] as $write) {
				try {
					$write ? $service->copy($this->tree->rootFolder(), 'alice', '/Documentos/ata.md', '/' . $folder, null, false)
						: $service->planCopy($this->tree->rootFolder(), 'alice', '/Documentos/ata.md', '/' . $folder, null);
					self::fail('Copy destination reservation bypassed');
				} catch (ToolFailure $error) {
					self::assertSame(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath(), $error->getMessage());
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
			try { \OCA\Mcp\Modules\Files\Backups\BackupWriteBoundary::afterCopy($backup, '/a', $copy); self::fail('Late refusal bypassed'); }
			catch (ToolFailure $error) {
				self::assertSame('Reserved path ' . \OCA\Mcp\Modules\Files\Presentation\EditingMessages::originalKept($copy), $error->getMessage());
				if ($copy !== '') { self::assertSame(1, substr_count($error->getMessage(), $copy)); }
				else { self::assertStringNotContainsString('original is kept', $error->getMessage()); }
			}
		}
		$backup = $this->createMock(FileBackup::class);
		$backup->method('assertWritablePath')->willThrowException(new \RuntimeException('unrelated'));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('unrelated');
		\OCA\Mcp\Modules\Files\Backups\BackupWriteBoundary::afterCopy($backup, '/a', '/copy');
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
			$access = new \OCA\Mcp\Platform\FileAccess\NodeAccessInfo($this->users, $this->tree->shareManager());
			$restore = new \OCA\Mcp\Modules\Files\Integration\VersionRestore($this->apps, $this->users, new \OCA\Mcp\Modules\Files\Writing\ContentTypeGate(\OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture::off($this)), $access, $container);
			$file = $this->tree->node('/alice/files/Documentos/ata.md');
			$before = $file->getContent();
			try { $restore->restore($this->tree->rootFolder(), $file, '/Documentos/ata.md', '1', 'alice', $backup); self::fail('Late reservation written'); }
			catch (ToolFailure $error) {
				self::assertSame(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath() . ' ' . \OCA\Mcp\Modules\Files\Presentation\EditingMessages::originalKept($copy), $error->getMessage());
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
		$access = new \OCA\Mcp\Platform\FileAccess\NodeAccessInfo($this->users, $this->tree->shareManager());
		$logger = new \Psr\Log\NullLogger();
		$root = $this->visibilityRoot();
		$tokens = new \OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore();
		$session = $this->createMock(\OCP\IUserSession::class);
		$refusals = new \OCA\Mcp\Modules\Files\Checkout\CheckoutRefusals($logger);
		$language = new \OCA\Mcp\Modules\Files\Checkout\CheckoutLanguage($tokens,
			new \OCA\Mcp\OAuth\Storage\TokenHasher($this->config->mock($this)), $session);
		$policy = new \OCA\Mcp\Modules\Files\Checkout\CheckoutPolicy($refusals, $tokens, $this->sharePolicy, $session, $this->time);
		$resolver = new \OCA\Mcp\Modules\Files\Checkout\CheckoutResolver($policy, $language, $refusals, $root);
		$handler = new \OCA\Mcp\Modules\Files\Checkout\CheckoutWriter($resolver, $policy, $language, $refusals, $root, $backup,
			new \OCA\Mcp\Platform\FileAccess\SharedWriteGuard($access), $access, $logger,
			fileTypes: \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture::off($this));
		$row = new \OCA\Mcp\Modules\Files\Checkout\CheckoutToken(1, 'upload', 'alice', $file->getId(), '/Documentos/ata.md', $file->getEtag(), 'personal', true, PHP_INT_MAX, true);
		$response = $handler->write($row, $file, '/body-must-not-be-read');
		self::assertSame(400, $response->getStatus());
		self::assertSame(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath() . ' ' . \OCA\Mcp\Modules\Files\Presentation\EditingMessages::originalKept($copy), $response->getData());
		self::assertSame($before, $file->getContent());
		if ($createCopy) {
			self::assertNotSame('', $copy);
			self::assertSame($before, $this->tree->rootFolder()->get($copy)->getContent());
			self::assertSame(1, substr_count($response->getData(), $copy));
		} else {
			self::assertSame('', $copy);
			self::assertFalse($this->tree->rootFolder()->nodeExists('.mcp-backups'));
		}
	}


	/**
	 * @param \OCA\Mcp\Access\ResourceScope\FilesAccessGuard|null $guard scope and exclusion tags of the upload
	 * @param \OCA\Mcp\Platform\FileAccess\NodeAccessInfo|null $access access description, the real one when null
	 * @param FileBackup|null $backup backup service, copies on in the default folder when null
	 * @return array{\OCA\Mcp\Modules\Files\Checkout\CheckoutWriter, \OCA\Mcp\Modules\Files\Checkout\CheckoutToken, \OCP\Files\File, string} writer, spent upload token, file and body
	 */
	private function checkoutUpload(?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $guard = null, ?\OCA\Mcp\Platform\FileAccess\NodeAccessInfo $access = null, ?FileBackup $backup = null): array {
		$real = new \OCA\Mcp\Platform\FileAccess\NodeAccessInfo($this->users, $this->tree->shareManager());
		$logger = new \Psr\Log\NullLogger();
		$root = $this->visibilityRoot();
		$tokens = new \OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore();
		$session = $this->createMock(\OCP\IUserSession::class);
		$refusals = new \OCA\Mcp\Modules\Files\Checkout\CheckoutRefusals($logger);
		$language = new \OCA\Mcp\Modules\Files\Checkout\CheckoutLanguage($tokens, new \OCA\Mcp\OAuth\Storage\TokenHasher($this->config->mock($this)), $session);
		$policy = new \OCA\Mcp\Modules\Files\Checkout\CheckoutPolicy($refusals, $tokens, $this->sharePolicy, $session, $this->time);
		$resolver = new \OCA\Mcp\Modules\Files\Checkout\CheckoutResolver($policy, $language, $refusals, $root);
		$backup ??= new FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this));
		$writer = new \OCA\Mcp\Modules\Files\Checkout\CheckoutWriter($resolver, $policy, $language, $refusals, $root, $backup,
			new \OCA\Mcp\Platform\FileAccess\SharedWriteGuard($real), $access ?? $real, $logger, null, $guard,
			fileTypes: \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture::off($this));
		$file = $this->tree->node('/alice/files/Documentos/ata.md');
		$row = new \OCA\Mcp\Modules\Files\Checkout\CheckoutToken(1, 'upload', 'alice', $file->getId(), '/Documentos/ata.md', $file->getEtag(), 'personal', true, PHP_INT_MAX, true);
		$body = (string)tempnam(sys_get_temp_dir(), 'mcp');
		file_put_contents($body, 'novo');
		return [$writer, $row, $file, $body];
	}

	/** The file left the scope while it was written and the storage then failed: 404 with the plain refusal, no path of the copy. */
	public function testCheckoutUploadThatFailsOnceTheFileIsHiddenDoesNotNameTheCopy(): void {
		$hidden = false;
		$mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use (&$hidden): array {
			return array_fill_keys($ids, $hidden ? ['999'] : []);
		});
		$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["999"]';
		$guard = new \OCA\Mcp\Access\ResourceScope\FilesAccessGuard($this->config->mock($this), $mapper, $this->visibilityRoot());
		[$writer, $row, $file, $body] = $this->checkoutUpload($guard);
		$this->tree->writeFailure = new \RuntimeException('storage failed');
		$this->tree->beforeWrite = static function (string $path) use (&$hidden): void {
			if ($path === '/alice/files/Documentos/ata.md') {
				$hidden = true;
			}
		};
		$response = $writer->write($row, $file, $body);
		self::assertSame(404, $response->getStatus());
		self::assertSame(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound(), $response->getData());
	}

	/** Nextcloud took the read permission away in a hook of the upload: the refusal of the re-read names the copy. */
	public function testCheckoutUploadThatLosesReadAfterTheWriteNamesTheCopy(): void {
		[$writer, $row, $file, $body] = $this->checkoutUpload();
		$this->tree->afterWrite = function (string $path): void {
			if ($path === '/alice/files/Documentos/ata.md') {
				$this->tree->nodes[$path]['readable'] = false;
			}
		};
		$response = $writer->write($row, $file, $body);
		self::assertSame(400, $response->getStatus());
		self::assertMatchesRegularExpression('#^' . preg_quote(\OCA\Mcp\Platform\FileAccess\CommonMessages::forbidden(), '#') . ' The original is kept in /MCP backups/Documentos/ata\.md\.[0-9-]+\.bak\.$#', $response->getData());
		self::assertSame('novo', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
	}

	/** The upload went through, but Nextcloud cannot describe the file for the receipt: 404 with the refusal and the copy. */
	public function testCheckoutReceiptMetadataThatCannotBeReadStillNamesTheCopy(): void {
		$access = $this->createMock(\OCA\Mcp\Platform\FileAccess\NodeAccessInfo::class);
		$calls = 0;
		$access->method('describe')->willReturnCallback(static function () use (&$calls): array {
			if (++$calls > 1) {
				throw new \OCP\Files\NotFoundException('gone');
			}
			return ['scope' => 'personal', 'owner' => 'alice', 'ownerDisplayName' => 'Alice', 'permissions' => ['update' => true]];
		});
		[$writer, $row, $file, $body] = $this->checkoutUpload(null, $access);
		$response = $writer->write($row, $file, $body);
		self::assertSame(404, $response->getStatus());
		self::assertMatchesRegularExpression('#^' . preg_quote(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound(), '#') . ' The original is kept in /MCP backups/Documentos/ata\.md\.[0-9-]+\.bak\.$#', $response->getData());
	}

	/**
	 * @param bool $copies effective copy preference
	 * @return void a transactional lock met after the copy answers 423 with the truthful copy note and the new-checkout advice
	 */
	#[DataProvider('copyPreferences')]
	public function testCheckoutTransactionalLockAfterTheCopyCarriesTheCopyNote(bool $copies): void {
		[, , $backup] = $this->settings('MCP backups', $copies);
		[$writer, $row, $file, $body] = $this->checkoutUpload(null, null, $backup);
		$this->tree->writeFailure = new \OCP\Lock\LockedException('/alice/files/Documentos/ata.md');
		$response = $writer->write($row, $file, $body);
		self::assertSame(423, $response->getStatus());
		$note = $copies
			? 'The original is kept in /MCP backups/Documentos/ata\.md\.[0-9-]+\.bak\.'
			: preg_quote(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::originalKept(''), '#');
		self::assertMatchesRegularExpression('#^' . preg_quote(\OCA\Mcp\Platform\FileAccess\CommonMessages::locked(), '#') . ' ' . $note . ' '
			. preg_quote(\OCA\Mcp\Platform\FileAccess\LockMessages::newCheckoutNeeded(), '#') . '$#', $response->getData());
	}

	/**
	 * @param bool $whileChecking whether the scope fails while the visibility is checked again or during the write itself
	 * @return void an unreadable scope answers 503 and asks for a new checkout, since the upload link is already spent
	 */
	#[DataProvider('scopeFailures')]
	public function testCheckoutScopeThatCannotBeReadAfterTheSpendAsksForANewCheckout(bool $whileChecking): void {
		$guard = null;
		if ($whileChecking) {
			$guard = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
			$guard->method('isVisible')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable());
			$this->tree->writeFailure = new \RuntimeException('storage failed');
		} else {
			$this->tree->writeFailure = new \OCA\Mcp\Access\Policy\PolicyUnavailable();
		}
		[$writer, $row, $file, $body] = $this->checkoutUpload($guard);
		$response = $writer->write($row, $file, $body);
		self::assertSame(503, $response->getStatus());
		self::assertSame(\OCA\Mcp\Modules\Files\Presentation\CheckoutMessages::uploadUnavailableAfterSpend(), $response->getData());
		self::assertStringNotContainsString('ata.md', $response->getData());
	}

	/** @return iterable<string, array{bool}> where the unreadable scope is met */
	public static function scopeFailures(): iterable {
		yield 'during the write' => [false];
		yield 'while checking visibility again' => [true];
	}
}
