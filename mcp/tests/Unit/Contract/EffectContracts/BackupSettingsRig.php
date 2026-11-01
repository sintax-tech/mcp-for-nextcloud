<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\BackupSettingsController;
use OCA\Mcp\Db;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Platform\Backups\BackupCopies;
use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\Backups\BackupSettingsDocument;
use OCA\Mcp\Service\BackupSettingsWriter;
use OCA\Mcp\Service\Policy\PolicyEventProcessor;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IFilenameValidator;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `backup_settings#update` as the container wires it, for its effect contract: the real {@see BackupSettingsController},
 * {@see BackupSettingsWriter}, {@see BackupLocation} (reading the real {@see BackupSettingsDocument}) and
 * {@see PolicyWriter}, all on one SQLite database that also holds the `appconfig` table the official configuration
 * writes to, the way BackupSettingsTest builds it. The Versions app is enabled; the core file name validator refuses
 * only `core-rejects-this`.
 */
final class BackupSettingsRig {
	public readonly SqliteDatabase $database;
	private PolicyWriter $writer;
	private BackupSettingsWriter $settings;
	private BackupLocation $location;
	private BackupCopies $copies;
	private Db\PolicyStateMapper $state;
	/** @var array<string, string> the official writer's value cache, cleared like the core clears it */
	private array $cache = [];
	/** @var array<string, mixed> parameters of the next request */
	private array $body = [];

	/** @param TestCase $test the running test, owner of the doubles */
	public function __construct(private TestCase $test) {
		$this->database = new SqliteDatabase($test, [new Version001100Date20261015000000($this->mock(IDBConnection::class)), new Version001100Date20261015010000()]);
		$db = $this->database->connection();
		(new Version001100Date20261015000000($db))->postSchemaChange($this->mock(\OCP\Migration\IOutput::class), static fn () => null, []);
		$this->database->dbal->executeStatement('CREATE TABLE appconfig (appid TEXT NOT NULL, configkey TEXT NOT NULL, configvalue TEXT NOT NULL, '
			. 'type INTEGER NOT NULL, lazy INTEGER NOT NULL, PRIMARY KEY(appid, configkey))');
		$config = $this->appConfig();
		$this->location = new BackupLocation(new BackupSettingsDocument($db));
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturn($this->user());
		$this->copies = new BackupCopies($this->location, $apps, $users);
		$time = $this->mock(ITimeFactory::class);
		$events = new Db\PolicyEventsMapper($db, $time);
		$oauth = new InMemoryOAuthStore();
		$this->state = new Db\PolicyStateMapper($db);
		$this->writer = new PolicyWriter($db, $this->state, $events, (new InMemoryConfig())->mock($test),
			$this->mock(\OCP\Config\IUserConfig::class), $config, $users, $time, new Db\AuthUsersMapper($db), new Db\RecheckMapper($db),
			new PolicyEventProcessor($events, $oauth, new \Psr\Log\NullLogger(), $time), new Db\GroupGrantsMapper($db),
			new Db\GroupLifeMapper($db), $oauth);
		$filenames = $this->mock(IFilenameValidator::class);
		$filenames->method('validateFilename')->willReturnCallback(static function (string $name): void {
			if ($name === 'core-rejects-this') {
				throw new \OCP\Files\InvalidPathException();
			}
		});
		$this->settings = new BackupSettingsWriter($this->location, $this->copies, $this->writer, $config, $filenames);
	}

	/** @return PolicyWriter the writer of every save */
	public function writer(): PolicyWriter {
		return $this->writer;
	}

	/**
	 * @param array<string, mixed> $body parameters of the request
	 * @return BackupSettingsController the controller of one request, signed in as root
	 */
	public function controller(array $body): BackupSettingsController {
		$this->body = $body;
		$request = $this->mock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->body)
			? $this->body[$key] : $default);
		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user());
		return new BackupSettingsController('mcp', $request, $this->location, $this->copies, $this->settings, $this->state, $session);
	}

	/** @return array<string, mixed> the stored backup setting, the revision and the policy event kinds */
	public function snapshot(): array {
		$dbal = $this->database->dbal;
		return [
			'appconfig' => $dbal->fetchAllAssociative('SELECT configkey, configvalue FROM appconfig WHERE appid = ? ORDER BY configkey', ['mcp']),
			'revision' => $this->state->state()['revision'],
			'events' => array_column($dbal->fetchAllAssociative('SELECT kind FROM ' . Db\PolicyEventsMapper::TABLE . ' ORDER BY rowid'), 'kind'),
		];
	}

	/** @return IAppConfig the official configuration over the appconfig table, with the core's value cache */
	private function appConfig(): IAppConfig {
		$config = $this->mock(IAppConfig::class);
		$config->method('clearCache')->willReturnCallback(function (): void {
			$this->cache = [];
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			if (($this->cache[$app . '/' . $key] ?? null) === $value) {
				return false;
			}
			$this->cache[$app . '/' . $key] = $value;
			$this->database->dbal->executeStatement('DELETE FROM appconfig WHERE appid=? AND configkey=?', [$app, $key]);
			$this->database->dbal->insert('appconfig', ['appid' => $app, 'configkey' => $key, 'configvalue' => $value,
				'type' => IAppConfig::VALUE_STRING, 'lazy' => 0]);
			return true;
		});
		return $config;
	}

	/** @return IUser root, the administrator of every request */
	private function user(): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn('root');
		return $user;
	}

	/**
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private function mock(string $class): MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
