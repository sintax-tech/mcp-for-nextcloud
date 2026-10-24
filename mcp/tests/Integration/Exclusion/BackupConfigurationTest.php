<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupCopies;
use OCA\Mcp\Service\BackupSettingsWriter;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real NC35 proof of configured destinations, copy suspension and historical protection across Files surfaces. */
final class BackupConfigurationTest extends ExclusionTestCase {
	/** @var string|null previous document, restored after each disposable scenario */
	private ?string $before = null;

	/** @return void preserve configuration before each scenario */
	protected function setUp(): void {
		parent::setUp();
		$config = Server::get(IAppConfig::class);
		$config->clearCache(false);
		$this->before = $config->hasKey('mcp', BackupLocation::KEY, null) ? $config->getValueString('mcp', BackupLocation::KEY, '', false) : null;
	}

	/** @return void restore the document; only this test's named data is removed */
	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		$this->before === null ? $config->deleteKey('mcp', BackupLocation::KEY) : $config->setValueString('mcp', BackupLocation::KEY, $this->before, false);
		$config->clearCache(false);
		parent::tearDown();
	}

	/**
	 * @param string $folder new root name
	 * @param bool $copies whether Files copies remain enabled
	 * @return void save under the real existing policy revision transaction
	 */
	private function configure(string $folder, bool $copies): void {
		Server::get(BackupSettingsWriter::class)->save(Server::get(PolicyStateMapper::class)->state()['revision'], $folder, $copies);
	}

	/** @return iterable<string, array{bool}> administrator copy preference */
	public static function preferences(): iterable {
		yield 'copies enabled' => [true];
		yield 'copies suspended' => [false];
	}

	/**
	 * @param array<string, mixed> $data write receipt
	 * @param bool $copies expected copy creation
	 * @return void verify the actual copy or its explicit absence
	 */
	private function assertCopy(array $data, bool $copies): void {
		if (!$copies) {
			self::assertSame('', $data['backup']);
			return;
		}
		self::assertStringStartsWith('/.mcp-backups/', $data['backup']);
		self::assertTrue($this->userFolder(self::ALICE)->nodeExists(ltrim($data['backup'], '/')));
	}

	/**
	 * @param bool $copies configured preference with the Versions app active
	 * @return void edit, replace, checkout upload and native restore follow the same current setting
	 */
	#[DataProvider('preferences')]
	public function testAllFileOverwritesUseThePreference(bool $copies): void {
		$this->configure('.mcp-backups', $copies);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, '.mcp-backups/' . $this->run));
		$this->makeFile('home', 'a.txt', 'alpha');
		$path = $this->path('home', 'a.txt');
		$mcp = $this->mcp();
		$edit = $mcp->write('files_edit', ['path' => $path, 'content' => 'beta']);
		self::assertTrue($edit->ok(), $edit->describe());
		$this->assertCopy($edit->json(), $copies);
		$replace = $mcp->write('files_replace', ['path' => $path, 'old' => 'beta', 'new' => 'gamma']);
		self::assertTrue($replace->ok(), $replace->describe());
		$this->assertCopy($replace->json(), $copies);
		$checkout = $mcp->write('files_checkout', ['path' => $path]);
		self::assertTrue($checkout->ok(), $checkout->describe());
		$url = Http::BASE . parse_url($checkout->json()['upload_url'], PHP_URL_PATH);
		$upload = Http::request('PUT', $url, ['Content-Type: application/octet-stream'], 'delta');
		self::assertSame(200, $upload['status'], $upload['body']);
		$this->assertCopy(json_decode($upload['body'], true), $copies);
		$versions = $mcp->tool('files_versions_list', ['path' => $path]);
		self::assertTrue($versions->ok(), $versions->describe());
		$revision = $versions->json()['versions'][0]['revision'] ?? null;
		self::assertIsString($revision, 'Native version must exist in the provisioned test backend');
		$restore = $mcp->write('files_version_restore', ['path' => $path, 'version' => $revision]);
		self::assertTrue($restore->ok(), $restore->describe());
		$this->assertCopy($restore->json(), $copies);
	}

	/** @return void changing A to B to A preserves copy files and applies mirror exclusions to every reserved name */
	public function testHistoricNamesStayProtectedAndHidden(): void {
		$original = $this->makeFile('home', 'secret.txt', 'private');
		$this->tag($original);
		$this->exclude();
		foreach (['.mcp-backups', 'BK2 archived', '.mcp-backups'] as $folder) {
			$this->configure($folder, true);
		}
		self::assertContains('MCP backups', Server::get(BackupLocation::class)->current()['reserved']);
		foreach (['MCP backups', '.mcp-backups', 'BK2 archived'] as $folder) {
			$relative = $folder . '/' . $this->run;
			$this->later(fn () => $this->deleteQuietly(self::ALICE, $relative));
			$mirror = $this->ensureFolder(self::ALICE, $relative)->newFile('secret.txt.20261024-120000.bak', 'private');
			$guard = Server::get(VisibilityGuard::class);
			$guard->clearCache();
			self::assertFalse($guard->isVisible($mirror));
			$write = $this->mcp()->write('files_create', ['path' => '/' . $folder . '/' . $this->run . '/new.txt', 'content' => 'blocked']);
			self::assertTrue($write->isError(), $write->describe());
			self::assertSame('private', $mirror->getContent());
		}
	}

	/** @return void Contacts copies are still verified and mandatory while Files copies are suspended */
	public function testContactBackupIsIndependentOfTheFilesPreference(): void {
		$this->configure('.mcp-backups', false);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, '.mcp-backups/Contacts/' . $this->run));
		$card = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Disposable\r\nUID:bk2\r\nEND:VCARD\r\n";
		$path = Server::get(ContactBackup::class)->save(self::ALICE, $this->run, 'Disposable', $card);
		self::assertStringStartsWith('/.mcp-backups/Contacts/', $path);
		self::assertSame($card, $this->userFolder(self::ALICE)->get($path)->getContent());
	}

	/** @return void losing the Versions app keeps the historical write refusal and makes copies effectively required */
	public function testDisablingVersionsDoesNotEnablePreviouslyForbiddenWrites(): void {
		$this->configure('.mcp-backups', false);
		$this->makeFile('home', 'a.txt', 'unchanged');
		$apps = Server::get(IAppManager::class);
		try {
			$apps->disableApp('files_versions');
			self::assertTrue(Server::get(BackupCopies::class)->required(self::ALICE));
			$edit = $this->mcp()->write('files_edit', ['path' => $this->path('home', 'a.txt'), 'content' => 'must not write']);
			self::assertTrue($edit->isError(), $edit->describe());
			self::assertSame('unchanged', $this->node(self::ALICE, $this->path('home', 'a.txt'))->getContent());
		} finally {
			$apps->enableApp('files_versions');
		}
	}
	/** @return void core NFC aliases cannot bypass the reservation, and pasted NFD configuration matches the actual backup folder */
	public function testCoreUnicodeNamesRemainReservedAndMirrorsHidden(): void {
		$nfd = "Co\u{0301}pias MCP";
		$nfc = 'Cópias MCP';
		self::assertSame('/' . $nfc . '/x', \OC\Files\Filesystem::normalizePath('/' . $nfd . '/x'));
		self::assertSame($nfc, \OC_Util::normalizeUnicode($nfd));
		$this->configure($nfd, true);
		self::assertSame($nfc, Server::get(BackupLocation::class)->current()['folder']);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, $nfc . '/' . $this->run));
		$original = $this->makeFile('home', 'unicode.txt', 'original');
		$edit = $this->mcp()->write('files_edit', ['path' => $this->path('home', 'unicode.txt'), 'content' => 'changed']);
		self::assertTrue($edit->ok(), $edit->describe());
		$backup = $edit->json()['backup'];
		self::assertStringStartsWith('/' . $nfc . '/', $backup);
		$home = $this->userFolder(self::ALICE);
		self::assertContains($nfc, array_map(static fn ($node): string => $node->getName(), $home->getDirectoryListing()));
		self::assertSame($home->get($nfc)->getId(), $home->get($nfd)->getId());
		self::assertSame($nfd, $home->get($nfd)->getName(), 'A Node alias retains its requested spelling while View resolves the same NFC identity');
		$path = '/' . $nfd . '/' . $this->run . '/must-not-exist.txt';
		$refused = $this->mcp()->write('files_create', ['path' => $path, 'content' => 'must not write']);
		self::assertTrue($refused->isError(), $refused->describe());
		self::assertStringContainsString(\OCA\Mcp\Tools\Files\FilesMessages::backupPath(), $refused->fingerprint());
		self::assertFalse($home->nodeExists($nfc . '/' . $this->run . '/must-not-exist.txt'));
		$this->tag($original);
		$this->exclude();
		$guard = Server::get(VisibilityGuard::class);
		$guard->clearCache();
		self::assertFalse($guard->isVisible($home->get($backup)));
		self::assertFalse($guard->isVisible($home->get(str_replace($nfc, $nfd, $backup))));
	}

	/** @return void malformed metadata never permits writes, creates a copy, or releases any previous name */
	public function testCorruptSettingsRefuseWritesAndMandatoryCopies(): void {
		$this->configure('BK2 history', true);
		$this->makeFile('home', 'corruption.txt', 'unchanged');
		$home = $this->userFolder(self::ALICE);
		$config = Server::get(IAppConfig::class);
		foreach (['{invalid', '{}'] as $raw) {
			$config->setValueString('mcp', BackupLocation::KEY, $raw, false);
			$config->clearCache(false);
			foreach ([['files_edit', ['path' => $this->path('home', 'corruption.txt'), 'content' => 'forbidden']], ['files_copy', ['from' => $this->path('home', 'corruption.txt'), 'to' => $this->path('home', 'copy.txt')]], ['files_create', ['path' => '/BK2 history/' . $this->run . '/forbidden.txt', 'content' => 'forbidden']]] as [$tool, $arguments]) {
				$result = $this->mcp()->write($tool, $arguments);
				self::assertTrue($result->isError(), $result->describe());
			}
			try { Server::get(ContactBackup::class)->save(self::ALICE, $this->run, 'corrupt', 'private'); self::fail('Contact copy bypassed corruption'); }
			catch (\OCA\Mcp\Tools\ToolFailure) { }
			self::assertFalse($home->nodeExists('BK2 history/' . $this->run . '/forbidden.txt'));
			self::assertFalse($home->nodeExists(ltrim($this->path('home', 'copy.txt'), '/')));
			self::assertSame('unchanged', $home->get($this->path('home', 'corruption.txt'))->getContent());
		}
	}

	/** @return void fresh boundaries see another writer without clearing the server cache; admin mutation retains that history */
	public function testFreshDocumentReadDoesNotInvalidateAppConfigCache(): void {
		$this->configure('BK3 A', true);
		$config = Server::get(IAppConfig::class);
		$cached = $config->getValueString('mcp', BackupLocation::KEY);
		$location = Server::get(BackupLocation::class);
		self::assertSame('BK3 A', $location->current()['folder']);
		$external = json_decode($cached, true, 16, JSON_THROW_ON_ERROR);
		$external['folder'] = 'BK3 B';
		$external['reserved'][] = 'BK3 B';
		$database = Server::get(\OCP\IDBConnection::class);
		$query = $database->getQueryBuilder();
		$query->update('appconfig')->set('configvalue', $query->createNamedParameter(json_encode($external, JSON_THROW_ON_ERROR)))
			->where($query->expr()->eq('appid', $query->createNamedParameter('mcp')))
			->andWhere($query->expr()->eq('configkey', $query->createNamedParameter(BackupLocation::KEY)))->executeStatement();
		self::assertSame('BK3 A', $location->snapshot()['folder']);
		$other = new BackupLocation(new \OCA\Mcp\Service\BackupSettingsDocument($database));
		self::assertSame('BK3 B', $other->current()['folder']);
		self::assertTrue(BackupLocation::contains('/BK3 B/forbidden.txt', $location));
		self::assertSame($cached, $config->getValueString('mcp', BackupLocation::KEY), 'Reading must not invalidate AppConfig cache');
		$this->configure('BK3 C', true);
		self::assertContains('BK3 B', $location->current()['reserved']);
		self::assertSame('BK3 C', $location->current()['folder']);
	}

}
