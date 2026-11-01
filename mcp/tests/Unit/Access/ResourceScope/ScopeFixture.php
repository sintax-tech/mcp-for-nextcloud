<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * Real policy state, events and scope tables on SQLite, the real writer, and the native resources of alice: a Files
 * tree with Projects (C1), Archive (C2), a tag-excluded Secret folder, an unreadable Locked folder and a file, plus
 * two calendars and a task list. The administrator is root.
 */
final class ScopeFixture {
	public const HIDDEN_TAG = '77';

	public SqliteDatabase $database;
	public IDBConnection $db;
	public PolicyStateMapper $state;
	public PolicyEventsMapper $events;
	public ResourceScopeMapper $scopes;
	public FakeTree $tree;
	public FakeCalendarStore $calendars;
	public InMemoryConfig $config;
	public InMemoryOAuthStore $oauth;
	public VisibilityGuard $guard;
	public int $now = 1760000000;
	/** @var list<string> */
	public array $users = ['alice', 'bob', 'root'];
	/** @var array<string, list<string>> tags by file id */
	public array $tags = [];
	public string $projects;
	public string $archive;
	public string $secret;
	public string $locked;
	public string $file;

	public function __construct(private TestCase $test) {
		$migration = new Version001100Date20261015000000($this->mock(IDBConnection::class));
		// The group grants table too, so the resolution core can read a snapshot over the same database.
		$this->database = new SqliteDatabase($test, [new Version001100Date20261014000000(), $migration, new Version001100Date20261015010000(), new Version001100Date20261015020000()]);
		$this->db = $this->database->connection();
		(new Version001100Date20261015000000($this->db))->postSchemaChange($this->mock(IOutput::class), fn () => null, []);
		$this->state = new PolicyStateMapper($this->db);
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$this->events = new PolicyEventsMapper($this->db, $time);
		$this->scopes = new ResourceScopeMapper($this->db);
		$this->config = new InMemoryConfig();
		$this->oauth = new InMemoryOAuthStore();

		$this->tree = new FakeTree($test, '/alice/files');
		$this->projects = (string)$this->tree->addFolder('/alice/files/Projects');
		$this->archive = (string)$this->tree->addFolder('/alice/files/Archive');
		$this->secret = (string)$this->tree->addFolder('/alice/files/Secret');
		$this->locked = (string)$this->tree->addFolder('/alice/files/Locked', ['readable' => false]);
		$this->file = (string)$this->tree->addFile('/alice/files/notes.txt', 'text');
		$this->tags = [$this->secret => [self::HIDDEN_TAG]];
		$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
		$mapper = $this->mock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
			$result = [];
			foreach ($ids as $id) {
				$result[(string)$id] = $this->tags[(string)$id] ?? [];
			}
			return $result;
		});
		$this->guard = new VisibilityGuard($this->config->mock($test), $mapper, $this->tree->filesystemRoot());

		$this->calendars = new FakeCalendarStore();
		$this->calendars->addCalendar('principals/users/alice', 11, 'personal', 'principals/users/alice', ['name' => 'Personal', 'components' => ['VEVENT']]);
		$this->calendars->addCalendar('principals/users/alice', 12, 'team_shared_by_bob', 'principals/users/bob', ['name' => 'Team', 'components' => ['VEVENT', 'VTODO']]);
		$this->calendars->addCalendar('principals/users/alice', 13, 'todo', 'principals/users/alice', ['name' => 'Todo', 'components' => ['VTODO']]);
		$this->calendars->addCalendar('principals/users/bob', 14, 'bob-only', 'principals/users/bob', ['name' => 'Bob only', 'components' => ['VEVENT']]);
	}

	public function enumerator(): NativeResourceEnumerator {
		$root = $this->mock(\OCP\Files\IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->tree->rootFolder() : throw new \OCP\Files\NotFoundException());
		return new NativeResourceEnumerator($root, new CalendarAccess($this->calendars), $this->guard);
	}

	/**
	 * @param bool $open whether every module's activation barrier is open; false closes all three, as before B5 and B6
	 * @param ScopeActivation|null $activation a barrier of its own instead, such as the shipped one (new ScopeActivation())
	 */
	public function writer(bool $open = true, ?NativeResourceEnumerator $resources = null, ?ScopeActivation $activation = null): PolicyWriter {
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		return new PolicyWriter($this->db, $this->state, $this->events, $this->config->mock($this->test),
			$this->mock(IUserConfig::class), $this->mock(IAppConfig::class), $this->userManager(), $time,
			new \OCA\Mcp\Db\AuthUsersMapper($this->db), new \OCA\Mcp\Db\RecheckMapper($this->db),
			new \OCA\Mcp\Access\Policy\PolicyEventProcessor($this->events, $this->oauth, new \Psr\Log\NullLogger(), $time),
			new \OCA\Mcp\Db\GroupGrantsMapper($this->db), new \OCA\Mcp\Db\GroupLifeMapper($this->db), $this->oauth,
			$this->scopes, $resources ?? $this->enumerator(),
			$activation ?? new ScopeActivation(['files' => $open, 'calendar' => $open, 'tasks' => $open]));
	}

	/**
	 * The resolution core over these tables, without any group: the policy every tool of alice reads.
	 *
	 * @param PolicyWriter $writer the writer whose context the resolution checks
	 */
	public function resolver(PolicyWriter $writer): \OCA\Mcp\Access\Policy\PolicyResolver {
		$backend = (new \ReflectionMethod($this->test, 'createMockForIntersectionOfInterfaces'))->invoke($this->test, [\OCP\GroupInterface::class, \OCP\Group\Backend\INamedBackend::class]);
		$backend->method('getBackendName')->willReturn('Database');
		$backend->method('getUserGroups')->willReturn([]);
		$backend->method('groupExists')->willReturn(false);
		$groups = $this->mock(\OCP\IGroupManager::class);
		$groups->method('getBackends')->willReturn([$backend]);
		$apps = $this->mock(\OCP\App\IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('getAppVersion')->willReturn('99.0.0');
		$logs = $this->mock(\OCA\Mcp\Access\Policy\LogsRoleReader::class);
		$logs->method('read')->willReturn(['available' => true, 'permitted' => true]);
		$life = new \OCA\Mcp\Db\GroupLifeMapper($this->db);
		return new \OCA\Mcp\Access\Policy\PolicyResolver($this->state, $writer, $this->config->mock($this->test),
			$this->mock(IUserConfig::class), $this->mock(IAppConfig::class), $this->userManager(),
			new \OCA\Mcp\Access\Policy\MembershipReader($groups), new \OCA\Mcp\Access\Policy\GroupSourceClassifier($groups),
			new \OCA\Mcp\Db\GroupGrantsMapper($this->db), $life,
			new \OCA\Mcp\Access\Policy\GroupTrustObserver($this->db, $writer, $life, $this->mock(\Psr\Log\LoggerInterface::class)),
			$apps, $logs, new ResourceScopeMapper($this->db));
	}

	/** The GrantPolicy the tools read, over resolver($writer). */
	public function policy(PolicyWriter $writer): \OCA\Mcp\Access\Grants\GrantPolicy {
		return new \OCA\Mcp\Access\Grants\GrantPolicy($this->config->mock($this->test), $this->mock(IUserConfig::class), $writer, $this->resolver($writer));
	}

	public function userManager(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid) => in_array($uid, $this->users, true) ? $this->user($uid) : null);
		return $users;
	}

	public function user(string $uid): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		return $user;
	}

	public function revision(): string {
		return $this->state->state()['revision'];
	}

	/** @return array{revision:string, auth_epoch:string, events:int, modes:int, items:int} everything a refused write must leave as it was */
	public function snapshot(): array {
		$state = $this->state->state();
		return [
			'revision' => $state['revision'],
			'auth_epoch' => (string)$state['auth_epoch'],
			'events' => (int)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM ' . PolicyEventsMapper::TABLE),
			'modes' => (int)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM ' . ResourceScopeMapper::MODES_TABLE),
			'items' => (int)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM ' . ResourceScopeMapper::ITEMS_TABLE),
		];
	}

	/** @return list<array{id:string, kind:string, payload:array}> policy events in insertion order */
	public function eventRows(): array {
		return array_map(static fn (array $row): array => ['id' => (string)$row['id'], 'kind' => (string)$row['kind'],
			'payload' => json_decode((string)$row['payload'], true)], $this->database->dbal->fetchAllAssociative('SELECT * FROM ' . PolicyEventsMapper::TABLE . ' ORDER BY rowid'));
	}

	private function mock(string $interface): object {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $interface);
	}
}
