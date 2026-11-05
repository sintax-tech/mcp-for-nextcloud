<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\GroupGrantsService;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\OAuth\Storage\OAuthStore;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use RuntimeException;

/**
 * The three facade commands roll back on the real mappers when the audit write fails before commit.
 * A second connection is the observer: seeing rollBack() is not the proof.
 */
final class CommandRollbackTest extends OAuthAccountTestCase {
	private const ACTOR = 'admin';
	/** @var list<string> */
	private array $groups = [];
	/** @var list<string> */
	private array $baselineEvents = [];

	protected function setUp(): void {
		parent::setUp();
		$this->baselineEvents = $this->eventIds($this->official());
	}

	protected function tearDown(): void {
		$db = $this->official();
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		foreach (array_diff($this->eventIds($db), $this->baselineEvents) as $id) {
			$qb = $db->getQueryBuilder();
			$qb->delete(PolicyEventsMapper::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
		}
		foreach ($this->groups as $gid) {
			foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))->executeStatement();
			}
			Server::get(IGroupManager::class)->get($gid)?->delete();
		}
		foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($this->uid))))->executeStatement();
		}
		parent::tearDown();
	}

	public function testGroupWithdrawalRollsBackAfterTheAuditWrite(): void {
		$this->exchange($this->consent());
		$gid = $this->group();
		Server::get(IConfig::class)->deleteUserValue($this->uid, 'mcp', 'eligible');
		Server::get(IUserConfig::class)->clearCache($this->uid, false);
		$admit = $this->admit();
		$preview = $this->preview($gid, ['_system' => ['eligible']]);
		$this->writer()->setGroupGrants($preview['revision'], self::ACTOR, $gid, $preview['grants'], $preview['previewId'], $admit);
		$withdrawal = $this->preview($gid, []);
		$this->assertTrue($withdrawal['withdraws']);
		$this->assertGreaterThan(0, $withdrawal['revokes']);
		// The preview commits on its own. The snapshot is the state the withdrawal has to restore.
		$before = $this->observe($gid);
		$this->assertRolledBack($gid, $before, function (PolicyEventsMapper $events) use ($withdrawal, $admit): void {
			$this->writer($events)->setGroupGrants($withdrawal['revision'], self::ACTOR, $gid, $withdrawal['grants'], $withdrawal['previewId'], $admit);
		}, function (array $open) use ($before): void {
			$this->assertNotSame($before['grants'], $open['grants']);
			$this->assertNotSame($before['events'], $open['events']);
		});
	}

	public function testScopeSaveRollsBackAfterTheAuditWrite(): void {
		$before = $this->observe('');
		$revision = Server::get(PolicyStateMapper::class)->state()['revision'];
		$this->assertRolledBack('', $before, function (PolicyEventsMapper $events) use ($revision): void {
			$this->writer($events, $this->resources())->setResourceScope($revision, self::ACTOR, $this->uid, 'calendar', ['4', '2']);
		}, function (array $open) use ($before): void {
			$this->assertNotSame($before['scope'], $open['scope']);
			$this->assertNotSame($before['events'], $open['events']);
		});
	}

	public function testUndoRollsBackAfterTheAuditWrite(): void {
		$resources = $this->resources();
		$revision = Server::get(PolicyStateMapper::class)->state()['revision'];
		$saved = $this->writer(null, $resources)->setResourceScope($revision, self::ACTOR, $this->uid, 'calendar', ['9']);
		$this->assertTrue($saved['changed']);
		$before = $this->observe('');
		$this->assertRolledBack('', $before, function (PolicyEventsMapper $events) use ($resources, $saved): void {
			$this->writer($events, $resources)->restoreReceipt((string)$saved['receiptId'], $saved['revision'], self::ACTOR);
		}, function (array $open) use ($before): void {
			$this->assertNotSame($before['scope'], $open['scope']);
			$this->assertNotSame($before['events'], $open['events']);
		});
	}

	public function testGrantUndoRollsBackAfterTheAuditWrite(): void {
		$revision = Server::get(PolicyStateMapper::class)->state()['revision'];
		$saved = $this->writer()->applyCells($revision, self::ACTOR, 'grants.commands', [
			['uid' => $this->uid, 'key' => 'grant_notes_edit', 'value' => '1'],
		]);
		$this->assertSame(1, $saved['changed']);
		$this->assertIsString($saved['receiptId']);
		$before = $this->observe('');
		$this->assertSame('1', $before['config']['grant_notes_edit'] ?? null);
		$this->assertRolledBack('', $before, function (PolicyEventsMapper $events) use ($saved): void {
			$this->writer($events)->restoreReceipt((string)$saved['receiptId'], $saved['revision'], self::ACTOR);
		}, function (array $open) use ($before): void {
			$this->assertNotSame($before['config'], $open['config']);
			$this->assertNotSame($before['events'], $open['events']);
		});
	}

	private function group(): string {
		$gid = 'mcp-it-rollback-' . bin2hex(random_bytes(6));
		$this->groups[] = $gid;
		$group = Server::get(IGroupManager::class)->createGroup($gid);
		$this->assertNotNull($group);
		$group->addUser($this->user);
		return $gid;
	}

	private function admit(): \Closure {
		$service = Server::get(GroupGrantsService::class);
		return fn (array $command): array => $service->admit($command);
	}

	/** @param array<string, list<string>> $grants */
	private function preview(string $gid, array $grants): array {
		return Server::get(GroupGrantsService::class)->preview(Server::get(PolicyStateMapper::class)->state()['revision'], self::ACTOR, $gid, $grants);
	}

	private function resources(): NativeResourceEnumerator {
		$resources = $this->createMock(NativeResourceEnumerator::class);
		$resources->method('inaccessible')->willReturn([]);
		return $resources;
	}

	private function failingEvents(\Closure $probe): PolicyEventsMapper {
		return new class($this->official(), Server::get(ITimeFactory::class), $probe) extends PolicyEventsMapper {
			public function __construct(IDBConnection $db, ITimeFactory $time, private \Closure $probe) {
				parent::__construct($db, $time);
			}

			public function append(string $id, string $kind, array $payload): void {
				parent::append($id, $kind, $payload);
				if ($kind === self::KIND_AUDIT) {
					($this->probe)();
					throw new RuntimeException('injected before commit');
				}
			}
		};
	}

	/**
	 * @param array<string, mixed> $before
	 * @param \Closure(PolicyEventsMapper): void $command
	 * @param \Closure(array<string, mixed>): void $written
	 */
	private function assertRolledBack(string $gid, array $before, \Closure $command, \Closure $written): void {
		try {
			$command($this->failingEvents(function () use ($gid, $before, $written): void {
				$this->assertTrue($this->official()->inTransaction());
				$open = $this->observeOn($this->official(), $gid);
				$written($open);
				$this->assertSame($before, $this->observe($gid));
			}));
			$this->fail('The command committed');
		} catch (RuntimeException $error) {
			$this->assertSame('injected before commit', $error->getMessage());
		}
		$this->assertFalse($this->official()->inTransaction());
		$this->assertSame($before, $this->observe($gid));
	}

	private function writer(?PolicyEventsMapper $events = null, ?NativeResourceEnumerator $resources = null): PolicyWriter {
		return new PolicyWriter($this->official(), Server::get(PolicyStateMapper::class), $events ?? Server::get(PolicyEventsMapper::class),
			Server::get(IConfig::class), Server::get(IUserConfig::class), Server::get(IAppConfig::class), Server::get(IUserManager::class),
			Server::get(ITimeFactory::class), Server::get(AuthUsersMapper::class), Server::get(\OCA\Mcp\Db\RecheckMapper::class),
			Server::get(\OCA\Mcp\Access\Policy\PolicyEventProcessor::class), Server::get(GroupGrantsMapper::class), Server::get(GroupLifeMapper::class),
			Server::get(OAuthStore::class), Server::get(ResourceScopeMapper::class), $resources ?? Server::get(NativeResourceEnumerator::class),
			new ScopeActivation(['files' => true, 'calendar' => true, 'tasks' => true]));
	}

	/** @return list<string> */
	private function eventIds(IDBConnection $db): array {
		$result = $db->getQueryBuilder()->select('id')->from(PolicyEventsMapper::TABLE)->orderBy('id')->executeQuery();
		try {
			$ids = array_map(static fn (array $row): string => (string)$row['id'], $result->fetchAll());
		} finally {
			$result->closeCursor();
		}
		sort($ids, SORT_STRING);
		return $ids;
	}

	/** @return array<string, mixed> */
	private function observe(string $gid): array {
		return $this->observeOn($this->openIndependentConnection(), $gid);
	}

	/** @return array<string, mixed> */
	private function observeOn(IDBConnection $second, string $gid): array {
		$users = clone Server::get(IUserConfig::class);
		(new \ReflectionProperty($users, 'connection'))->setValue($users, $second);
		$apps = clone Server::get(IAppConfig::class);
		(new \ReflectionProperty($apps, 'connection'))->setValue($apps, $second);
		$users->clearCache($this->uid, false);
		$apps->clearCache(false);
		$values = [];
		foreach (Server::get(IConfig::class)->getUserKeys($this->uid, 'mcp') as $key) {
			$values[$key] = $users->getValueString($this->uid, 'mcp', $key);
		}
		ksort($values, SORT_STRING);
		return [
			'state' => (new PolicyStateMapper($second))->state(),
			'life' => $gid === '' ? null : (new GroupLifeMapper($second))->get($gid),
			'grants' => $gid === '' ? [] : (new GroupGrantsMapper($second))->forGroups([$gid]),
			'generations' => (new AuthUsersMapper($second))->generations($this->uid),
			'scope' => (new ResourceScopeMapper($second))->forUser($this->uid),
			'events' => $this->eventIds($second),
			'config' => $values,
			'service' => $apps->getValueString('mcp', GrantPolicy::SERVICE_KEY, '0'),
		];
	}
}
