<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Db;
use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupCopies;
use OCA\Mcp\Service\BackupSettingsWriter;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\PolicyEventProcessor;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Tools\ToolFailure;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUser;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IFilenameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Backup persistence under the real policy transaction, including rollback and stale-revision admission. */
final class BackupSettingsTest extends TestCase {
	private SqliteDatabase $database;
	private IAppConfig $config;
	private int $loads = 0;
	private \OCA\Mcp\Service\BackupSettingsDocument $document;
	private int $invalidations = 0;
	/** @var array<string, string> models the official writer's value cache across transaction rollback */
	private array $writerCache = [];
	private BackupLocation $location;
	private BackupCopies $copies;
	private BackupSettingsWriter $settings;
	private bool $enabled = true;
	private bool $appFailure = false;
	private bool $readFailure = false;
	private bool $failVerification = false;

	/** @return void build one SQLite transaction shared by the official-config double and the real writer */
	protected function setUp(): void {
		$this->database = new SqliteDatabase($this, [new Version001100Date20261015000000($this->createMock(IDBConnection::class)), new Version001100Date20261015010000()]);
		$db = $this->database->connection();
		(new Version001100Date20261015000000($db))->postSchemaChange($this->createMock(\OCP\Migration\IOutput::class), fn () => null, []);
		$this->database->dbal->executeStatement('CREATE TABLE appconfig (appid TEXT NOT NULL, configkey TEXT NOT NULL, configvalue TEXT NOT NULL, type INTEGER NOT NULL, lazy INTEGER NOT NULL, PRIMARY KEY(appid, configkey))');
		$config = $this->config = $this->createMock(IAppConfig::class);
		$config->method('clearCache')->willReturnCallback(function (): void { $this->invalidations++; $this->writerCache = []; });
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			if (($this->writerCache[$app . '/' . $key] ?? null) === $value) {
				return false;
			}
			$this->writerCache[$app . '/' . $key] = $value;
			$this->database->dbal->executeStatement('DELETE FROM appconfig WHERE appid=? AND configkey=?', [$app, $key]);
			$this->database->dbal->insert('appconfig', ['appid' => $app, 'configkey' => $key, 'configvalue' => $value, 'type' => IAppConfig::VALUE_STRING, 'lazy' => 0]);
			if ($this->failVerification) {
				$this->readFailure = true;
			}
			return true;
		});
		$readerConnection = $this->createMock(IDBConnection::class);
		$readerConnection->method('getQueryBuilder')->willReturnCallback(function () use ($db) {
			$this->loads++;
			if ($this->readFailure) {
				throw new \RuntimeException('private backend');
			}
			return $db->getQueryBuilder();
		});
		$this->document = new \OCA\Mcp\Service\BackupSettingsDocument($readerConnection);
		$this->location = new BackupLocation($this->document);
		$apps = $this->createMock(IAppManager::class);
		$available = function (): bool {
			if ($this->appFailure) {
				throw new \RuntimeException('private backend');
			}
			return $this->enabled;
		};
		$apps->method('isEnabledForAnyone')->willReturnCallback($available);
		$apps->method('isEnabledForUser')->willReturnCallback($available);
		$users = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users->method('get')->willReturn($user);
		$this->copies = new BackupCopies($this->location, $apps, $users);
		$time = $this->createMock(ITimeFactory::class);
		$events = new Db\PolicyEventsMapper($db, $time);
		$oauth = new InMemoryOAuthStore();
		$writer = new PolicyWriter($db, new Db\PolicyStateMapper($db), $events, (new InMemoryConfig())->mock($this),
			$this->createMock(\OCP\Config\IUserConfig::class), $config, $users, $time,
			new Db\AuthUsersMapper($db), new Db\RecheckMapper($db), new PolicyEventProcessor($events, $oauth, new \Psr\Log\NullLogger(), $time),
			new Db\GroupGrantsMapper($db), new Db\GroupLifeMapper($db), $oauth);
		$filenames = $this->createMock(IFilenameValidator::class);
		$filenames->method('validateFilename')->willReturnCallback(static function (string $name): void {
			if ($name === 'core-rejects-this') {
				throw new \OCP\Files\InvalidPathException();
			}
		});
		$this->settings = new BackupSettingsWriter($this->location, $this->copies, $writer, $config, $filenames);
	}

	/** @return void missing configuration retains default behavior without creating any key */
	public function testDefaultIsReadWithoutWriting(): void {
		self::assertSame(BackupLocation::defaults(), $this->location->current());
		self::assertTrue($this->copies->required('alice'));
		self::assertSame(0, (int)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM appconfig'));
	}

	/** @return void A to B to A retains every reserved root name and rejects stale overwrites */
	public function testHistorySurvivesAbaAndStaleRevision(): void {
		self::assertSame('1', $this->settings->save('0', '.mcp-backups', true)['revision']);
		self::assertSame('2', $this->settings->save('1', 'Archives MCP', false)['revision']);
		self::assertSame('3', $this->settings->save('2', '.mcp-backups', true)['revision']);
		self::assertSame(['MCP backups', '.mcp-backups', 'Archives MCP'], $this->location->current()['reserved']);
		foreach ($this->location->current()['reserved'] as $name) {
			self::assertTrue(BackupLocation::contains('/' . $name . '/x', $this->location));
			self::assertFalse(BackupLocation::contains('/docs/' . $name . '/x', $this->location));
		}
		$this->expectException(PolicyConflict::class);
		$this->settings->save('1', 'Must not be saved', true);
	}

	/** @return iterable<string, array{string}> names rejected before any mutation */
	public static function badNames(): iterable {
		foreach (['', '.', '..', '/backups', 'a/b', 'a\\b', "a\n", "a\x7f", "a\u{0085}", "a\u{009f}", 'core-rejects-this'] as $name) {
			yield bin2hex($name) => [$name];
		}
	}

	/**
	 * @param string $name invalid input, including a name rejected only by the core validator
	 * @return void
	 */
	#[DataProvider('badNames')]
	public function testInvalidNameCannotChangeHistory(string $name): void {
		try {
			$this->settings->save('0', $name, true);
			self::fail('Invalid destination accepted');
		} catch (\InvalidArgumentException) {
		}
		self::assertSame(BackupLocation::defaults(), $this->location->current());
		self::assertSame('0', (new Db\PolicyStateMapper($this->database->connection()))->state()['revision']);
	}

	/** @return void a failed read-back rolls back the entire name/history/switch document and revision */
	public function testReadBackFailureRollsBackHistoryAndCurrentTogether(): void {
		$this->settings->save('0', 'A', true);
		$before = $this->location->current();
		$this->failVerification = true;
		try {
			$this->settings->save('1', 'B', false);
			self::fail('Failed verification accepted');
		} catch (ToolFailure) {
		}
		$this->readFailure = false;
		$this->failVerification = false;
		self::assertSame($before, $this->location->snapshot());
		self::assertSame('1', (new Db\PolicyStateMapper($this->database->connection()))->state()['revision']);
		self::assertSame('2', $this->settings->save('1', 'B', false)['revision']);
		self::assertSame(['MCP backups', 'A', 'B'], $this->location->current()['reserved']);
	}

	/** @return void native versioning loss or failure restores mandatory copies without changing the stored preference */
	public function testUnavailableVersionsRequireCopiesAndPreventSuspension(): void {
		$this->settings->save('0', 'A', false);
		self::assertFalse($this->copies->required('alice'));
		$this->enabled = false;
		self::assertTrue($this->copies->required('alice'));
		try {
			$this->settings->save('1', 'B', false);
			self::fail('Suspension without native versioning accepted');
		} catch (\InvalidArgumentException) {
		}
		self::assertSame('A', $this->location->current()['folder']);
		$this->appFailure = true;
		self::assertTrue($this->copies->required('alice'));
		$this->readFailure = true;
		self::assertTrue($this->copies->required('alice'));
	}
	/** @return void global admission never substitutes for per-user enablement during a Files operation */
	public function testGroupRestrictedVersioningIsEvaluatedForTheActualActor(): void {
		$this->settings->save('0', '.mcp-backups', false);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app, IUser $user): bool => $app === 'files_versions' && $user->getUID() === 'bob');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if ($uid === 'missing') {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		$copies = new BackupCopies($this->location, $apps, $users);
		self::assertTrue($copies->versionsAvailable());
		self::assertFalse($copies->required('bob'));
		self::assertTrue($copies->required('alice'));
		self::assertTrue($copies->required('missing'));
	}

	/** @return void pasted decomposed names and existing historical aliases use the filesystem's NFC identity */
	public function testUnicodeConfigurationAndHistoricalNamesAreCanonical(): void {
		$this->settings->save('0', "Co\u{0301}pias MCP", true);
		self::assertSame('Cópias MCP', $this->location->current()['folder']);
		self::assertTrue(BackupLocation::contains("/Co\u{0301}pias MCP/new.txt", $this->location));
		$old = ['version' => 1, 'folder' => "Co\u{0301}pias MCP", 'reserved' => ['MCP backups', "Co\u{0301}pias MCP", 'Cópias MCP'], 'copies' => true];
		$this->config->setValueString('mcp', BackupLocation::KEY, json_encode($old));
		self::assertSame(['MCP backups', 'Cópias MCP'], $this->location->current()['reserved']);
		self::assertTrue(BackupLocation::contains('/Cópias MCP/new.txt', $this->location));
		$this->expectException(\InvalidArgumentException::class);
		\OCA\Mcp\Service\BackupFolderName::normalize("invalid\xff");
	}

	/** @return void listings reuse a snapshot but explicit boundaries detect another instance and invalidation */
	public function testSnapshotsAreBoundedAndFreshBoundariesObserveOtherInstances(): void {
		$this->settings->save('0', 'A', true);
		$this->loads = 0;
		$invalidations = $this->invalidations;
		for ($i = 0; $i < 50; $i++) {
			self::assertSame('A', $this->location->snapshot()['folder']);
		}
		self::assertSame(1, $this->loads);
		$other = new BackupLocation($this->document);
		$this->config->setValueString('mcp', BackupLocation::KEY, json_encode(['version' => 1, 'folder' => 'B', 'reserved' => ['MCP backups', 'A', 'B'], 'copies' => false]));
		self::assertSame('B', $other->current()['folder']);
		self::assertSame('A', $this->location->snapshot()['folder']);
		self::assertTrue(BackupLocation::contains('/B/new.txt', $this->location));
		self::assertSame('B', $this->location->snapshot()['folder']);
		$this->location->invalidate();
		$before = $this->loads;
		$this->location->snapshot();
		self::assertSame($before + 1, $this->loads);
		self::assertSame($invalidations, $this->invalidations, 'Reads must not invalidate the global AppConfig cache');
	}

	/** @return void corrupt documents/read errors cannot turn an old reservation into permission or a default admin form */
	public function testCorruptionAndReadErrorsRefuseWithoutDefaults(): void {
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new \OCA\Mcp\Controller\BackupSettingsController('mcp', $this->createMock(\OCP\IRequest::class), $this->location, $this->copies, $this->settings, new Db\PolicyStateMapper($this->database->connection()), $session);
		foreach (['{invalid', '{}', '{"version":1,"folder":"A","reserved":[],"copies":true}', 'read-error'] as $raw) {
			$this->readFailure = $raw === 'read-error';
			$this->config->setValueString('mcp', BackupLocation::KEY, $raw);
			$this->location->invalidate();
			$start = $this->loads;
			for ($i = 0; $i < 3; $i++) {
				try { $this->location->snapshot(); self::fail('Corruption exposed defaults'); } catch (ToolFailure) { }
			}
			self::assertSame($start + 1, $this->loads);
			try { BackupLocation::contains('/A/new.txt', $this->location); self::fail('Old reservation released'); } catch (ToolFailure) { }
			self::assertTrue($this->copies->required('alice'));
			$response = $controller->show();
			self::assertSame(503, $response->getStatus());
			self::assertSame(['error' => 'Unavailable'], $response->getData());
		}
	}

	/** @return void only the intended document is read; lazy strings and legacy string types remain compatible */
	public function testReaderScopesTheKeyAndRejectsIncompatibleStorageTypes(): void {
		$this->config->setValueString('other-app', BackupLocation::KEY, 'not our document');
		$this->config->setValueString('mcp', 'unrelated', 'not our document');
		self::assertSame(BackupLocation::defaults(), $this->location->current());
		$this->config->setValueString('mcp', BackupLocation::KEY, json_encode(BackupLocation::defaults()));
		foreach ([0, IAppConfig::VALUE_MIXED, IAppConfig::VALUE_STRING] as $type) {
			$this->database->dbal->executeStatement('UPDATE appconfig SET type=?, lazy=1 WHERE appid=? AND configkey=?', [$type, 'mcp', BackupLocation::KEY]);
			self::assertSame(BackupLocation::defaults(), $this->location->current());
		}
		foreach ([IAppConfig::VALUE_INT, IAppConfig::VALUE_STRING | IAppConfig::VALUE_SENSITIVE, 128] as $type) {
			$this->database->dbal->executeStatement('UPDATE appconfig SET type=? WHERE appid=? AND configkey=?', [$type, 'mcp', BackupLocation::KEY]);
			try { $this->location->current(); self::fail('Incompatible storage type accepted'); } catch (ToolFailure) { }
		}
		self::assertSame(0, $this->invalidations);
	}

}
