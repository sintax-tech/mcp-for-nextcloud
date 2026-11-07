<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Modules\Files\Deletion\DeleteMessages;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

/** Real authenticated MCP preview/confirm, native trash restoration and negative admission. */
final class DeleteFileTest extends ExclusionTestCase {
	/** @return void Explicit opt-in is per test, never inherited from a fixture's historical Full. */
	protected function setUp(): void {
		parent::setUp();
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', true);
	}

	/** @return void Reset only this fixture's grant and known quota. */
	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey('files_trashbin', 'trashbin_size');
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', false);
		parent::tearDown();
	}

	/** @return void Both HTTP authentication modes see the warning, delete once, and native restoration has exact bytes. */
	public function testBasicAndBearerPreviewConfirmAndNativeRestore(): void {
		foreach (['basic', 'bearer'] as $kind) {
			$client = new McpClient($kind === 'bearer' ? LoginFactory::bearer(self::ALICE) : LoginFactory::basic(self::ALICE));
			$file = $this->makeFile('home', $kind . '.txt', 'fd1 exact native bytes');
			$path = $this->path('home', $kind . '.txt');
			$plan = $client->tool('files_delete', ['path' => $path]);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertTrue($plan->structured()['requiresConfirmation']);
			self::assertFalse($plan->structured()['recoveryGuaranteed']);
			self::assertStringContainsString(DeleteMessages::risk(), $plan->text());
			self::assertSame('fd1 exact native bytes', $file->getContent());
			$reply = $client->write('files_delete', ['path' => $path, 'id' => $plan->structured()['id'], 'etag' => $plan->structured()['etag']]);
			self::assertTrue($reply->ok(), $reply->describe());
			DeleteReceiptAssertions::observedInOwnTrash($reply->json());
			self::assertFalse($reply->json()['recoveryConfirmed']);
			self::assertFalse($this->exists(self::ALICE, $path));
			$this->restore($file->getName(), $path);
			self::assertSame('fd1 exact native bytes', $this->node(self::ALICE, $path)->getContent());
		}
	}

	/**
	 * @param string $name Original basename.
	 * @param string $path Original actor-relative path.
	 * @return void Restore through the native trash app API under the synthetic actor session.
	 */
	private function restore(string $name, string $path): void {
		$session = Server::get(IUserSession::class);
		$before = $session->getUser();
		$user = Server::get(IUserManager::class)->get(self::ALICE);
		$session->setUser($user);
		\OC_Util::setupFS(self::ALICE);
		try {
			$manager = Server::get('OCA\\Files_Trashbin\\Trash\\ITrashManager');
			$items = array_values(array_filter($manager->listTrashRoot($user), static fn ($item): bool => $item->getName() === $name));
			self::assertCount(1, $items, $path);
			$manager->restoreItem($items[0]);
		} finally {
			$session->setUser($before);
		}
	}

	/** @return void An old grant set without delete and an ungranted actor cause no effect; direct confirm is also gated. */
	public function testDefaultDeniedAndDifferentActorPreserveBytes(): void {
		$file = $this->makeFile('home', 'denied.txt', 'unchanged');
		$path = $this->path('home', 'denied.txt');
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', false);
		$reply = $this->mcp()->write('files_delete', ['path' => $path]);
		self::assertFalse($reply->ok());
		self::assertSame('unchanged', $file->getContent());
		$reply = $this->mcp(self::BOB)->write('files_delete', ['path' => $path]);
		self::assertFalse($reply->ok());
		self::assertSame('unchanged', $file->getContent());
	}

	/** @return void Stale identity and ETag are denied without sink or content disclosure. */
	public function testChangedIdentityAndEtagAreRefused(): void {
		$file = $this->makeFile('home', 'changed.txt', 'unchanged');
		foreach ([['id' => (int)$file->getId() + 10000], ['etag' => 'outdated']] as $extra) {
			$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'changed.txt')] + $extra);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertSame('unchanged', $file->getContent());
		}
	}

	/** @return void Excluded sources are refused in preview/confirm without leaking their names in a result. */
	public function testHiddenFileAndUnsupportedMountAndFolderAreRefused(): void {
		$file = $this->makeFile('home', 'private-name.txt', 'private bytes');
		$this->tag($file);
		$this->exclude();
		foreach ([false, true] as $confirm) {
			$reply = $this->mcp()->tool('files_delete', ['path' => $this->path('home', 'private-name.txt'), 'confirm' => $confirm]);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertStringNotContainsString('private-name', $reply->text());
			self::assertSame('private bytes', $file->getContent());
		}
		$this->setHidden([]);
		$external = $this->makeFile('external', 'external.txt', 'external bytes');
		foreach ([$this->path('external', 'external.txt'), '/'] as $path) {
			$reply = $this->mcp()->write('files_delete', ['path' => $path]);
			self::assertTrue($reply->isError(), $reply->describe());
		}
		self::assertSame('external bytes', $external->getContent());
	}

	/** @return void The explicit native trash-size limit refuses before deletion and preserves bytes. */
	public function testConfiguredTrashSizeLimitRefuses(): void {
		$file = $this->makeFile('home', 'quota.txt', 'too large');
		Server::get(IAppConfig::class)->setValueString('files_trashbin', 'trashbin_size', '1');
		$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'quota.txt')]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertSame('too large', $file->getContent());
	}
	/** @return void Sharing an ancestor requires explicit shared confirmation and otherwise preserves bytes. */
	public function testSharedAncestorRequiresExplicitConfirmation(): void {
		$file = $this->makeFile('home', 'shared-parent/a.txt', 'keep');
		$this->shareToUser($file->getParent(), self::ALICE, self::BOB);
		$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'shared-parent/a.txt')]);
		self::assertTrue($reply->json()['requiresSharedConfirmation']);
		self::assertSame('keep', $file->getContent());
	}

	/** @return void Scope exclusion answers like missing, and native trash OFF refuses without deleting. */
	public function testScopeAndAppOffRefuse(): void {
		$file = $this->makeFile('home', 'scoped.txt', 'keep');
		$writer = Server::get(\OCA\Mcp\Access\Policy\PolicyWriter::class);
		$state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
		$writer->setResourceScope($state->state()['revision'], 'admin', self::ALICE, 'files', []);
		try {
			$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'scoped.txt')]);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertStringNotContainsString('scoped.txt', $reply->text());
			self::assertSame('keep', $file->getContent());
		} finally {
			$writer->setResourceScope($state->state()['revision'], 'admin', self::ALICE, 'files', null);
		}
		$apps = Server::get(\OCP\App\IAppManager::class);
		$apps->disableApp('files_trashbin');
		try {
			$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'scoped.txt')]);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertSame('keep', $file->getContent());
		} finally {
			$apps->enableApp('files_trashbin');
		}
	}

	/** @return void Default, configured and historical NFC-equivalent reservations never reach deletion. */
	public function testCurrentHistoricAndUnicodeBackupNamesAreRefused(): void {
		$before = Server::get(\OCA\Mcp\Platform\Backups\BackupSettingsDocument::class)->read();
		$config = Server::get(IAppConfig::class);
		$writer = Server::get(\OCA\Mcp\Access\Settings\BackupSettingsWriter::class);
		$state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
		try {
			foreach (['Réservé', '.fd1-current'] as $name) {
				$writer->save($state->state()['revision'], $name, true);
			}
			foreach (['MCP backups', 'Réservé', '.fd1-current'] as $name) {
				$relative = $name . '/' . $this->run;
				$this->later(fn () => $this->deleteQuietly(self::ALICE, $relative));
				$file = $this->ensureFolder(self::ALICE, $relative)->newFile('reserved.txt', 'reserved bytes');
				$path = '/' . $relative . '/reserved.txt';
				if ($name === 'Réservé') {
					$path = str_replace('é', "e\u{0301}", $path);
				}
				foreach ([false, true] as $confirm) {
					$reply = $this->mcp()->tool('files_delete', ['path' => $path, 'confirm' => $confirm]);
					self::assertTrue($reply->isError(), $reply->describe());
					self::assertStringContainsString('backup', strtolower($reply->text()));
					self::assertSame('reserved bytes', $file->getContent());
				}
			}
		} finally {
			$before === null ? $config->deleteKey('mcp', \OCA\Mcp\Platform\Backups\BackupLocation::KEY)
				: $config->setValueString('mcp', \OCA\Mcp\Platform\Backups\BackupLocation::KEY, $before, false);
			Server::get(\OCA\Mcp\Platform\Backups\BackupLocation::class)->invalidate();
		}
	}

	/** @return void A real Noop provider is refused even if the configured locking switch still says enabled. */
	public function testActualNoopLockingProviderIsRefused(): void {
		$file = $this->makeFile('home', 'noop.txt', 'keep');
		$gate = Server::get(\OCA\Mcp\Modules\Files\Integration\DeleteBackendGate::class);
		$property = new \ReflectionProperty($gate, 'locking');
		$before = $property->getValue($gate);
		$property->setValue($gate, new \OC\Lock\NoopLockingProvider());
		try {
			try {
				$gate->check($file, self::ALICE);
				self::fail('Actual Noop locking must refuse');
			} catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $error) {
				self::assertStringContainsString('Nothing was deleted', $error->getMessage());
				self::assertSame('keep', $file->getContent());
			}
		} finally {
			$property->setValue($gate, $before);
		}
	}

}
