<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\ResourceScope\ScopeGuard;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Policy\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\GroupTrustObserver;
use OCA\Mcp\Access\Policy\MembershipReader;
use OCA\Mcp\Access\Policy\LogsRoleReader;
use OCA\Mcp\Access\Policy\PolicyOperationContext;
use OCA\Mcp\Access\Policy\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Access\Policy\PolicyEventProcessor;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Regression tests across the production resolver, native source classification and batch assembly. */
final class UnknownGroupGrantResolutionTest extends TestCase {
	private SqliteDatabase $database;
	private IDBConnection $db;
	private PolicyStateMapper $state;
	private GroupLifeMapper $life;
	private InMemoryConfig $data;
	private IConfig $config;
	private IUserConfig $userConfig;
	private IAppConfig $appConfig;
	private IUserManager $users;
	private IGroupManager $manager;
	private IAppManager $apps;
	private LogsAccess $logs;
	private PolicyWriter $writer;
	private PolicyResolver $resolver;
	private array $backends = [];
	private array $memberships = [];
	private array $exists = [];
	private int $membershipReads = 0;
	private int $classifications = 0;
	private bool $accountEnabled = true;

	/** @return void Production resolver with deterministic native service doubles. */
	protected function setUp(): void {
		$this->database = new SqliteDatabase($this, [new Version001100Date20261014000000(),
			new Version001100Date20261015000000($this->createMock(IDBConnection::class)),
			new Version001100Date20261015010000(), new Version001100Date20261015020000()]);
		$this->db = $this->database->connection();
		(new Version001100Date20261015000000($this->db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
		$this->state = new PolicyStateMapper($this->db);
		$this->life = new GroupLifeMapper($this->db);
		$this->data = new InMemoryConfig();
		$this->config = $this->data->mock($this);
		$this->data->app['mcp']['service_enabled'] = '1';
		$this->data->user['alice']['mcp']['connected'] = '1';
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('isEnabled')->willReturnCallback(fn () => $this->accountEnabled);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturn($user);
		$this->manager = $this->createMock(IGroupManager::class);
		$this->manager->method('getBackends')->willReturnCallback(fn () => $this->backends);
		$this->manager->expects($this->never())->method('get');
		$this->manager->expects($this->never())->method('getUserGroups');
		$this->manager->expects($this->never())->method('clearBackends');
		$this->apps = $this->createMock(IAppManager::class);
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->apps->method('getAppVersion')->willReturn('99.0.0');
		$this->logs = $this->createMock(LogsAccess::class);
		$this->logs->method('available')->willReturn(true);
		$this->logs->method('permits')->willReturn(true);
		$this->writer = new PolicyWriter($this->db, $this->state, $this->createMock(PolicyEventsMapper::class),
			$this->config, $this->userConfig, $this->appConfig, $this->users,
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), $this->createMock(\OCA\Mcp\Db\AuthUsersMapper::class),
			$this->createMock(\OCA\Mcp\Db\RecheckMapper::class), $this->createMock(PolicyEventProcessor::class),
			new \OCA\Mcp\Db\GroupGrantsMapper($this->db), new \OCA\Mcp\Db\GroupLifeMapper($this->db), new \OCA\Mcp\OAuth\OAuthStore($this->db));
		$this->resolver = $this->resolver();
	}

	/** @param PolicyStateMapper|null $state Revision source. @return PolicyResolver Production resolver. */
	private function resolver(?PolicyStateMapper $state = null): PolicyResolver {
		return new PolicyResolver($state ?? $this->state, $this->writer, $this->config, $this->userConfig,
			$this->appConfig, $this->users, new MembershipReader($this->manager), new GroupSourceClassifier($this->manager),
			new GroupGrantsMapper($this->db), $this->life,
			new GroupTrustObserver($this->db, $this->writer, $this->life, $this->createMock(LoggerInterface::class)),
			$this->apps, new LogsRoleReader($this->config, $this->logsGroups(), $this->manager, $this->createMock(\OCP\Security\Ip\IRemoteAddress::class), $this->createMock(\OCP\IUserSession::class)),
			new ResourceScopeMapper($this->db));
	}

	/** No group listed for the logs role: only administrators hold it. */
	private function logsGroups(): \OCA\Mcp\Db\LogsGroupsMapper {
		$mapper = $this->createMock(\OCA\Mcp\Db\LogsGroupsMapper::class);
		$mapper->method('state')->willReturn(['groups' => [], 'version' => 0]);
		return $mapper;
	}

	/** @param string|null $name Backend name. @param array $gids Groups served. @return GroupInterface Native backend double. */
	private function backend(?string $name = 'Database', array $gids = ['team']): GroupInterface {
		$backend = $name === null ? $this->createMock(GroupInterface::class)
			: $this->createMockForIntersectionOfInterfaces([GroupInterface::class, INamedBackend::class]);
		$id = spl_object_id($backend);
		$this->memberships[$id] = $this->exists[$id] = $gids;
		$backend->method('getUserGroups')->willReturnCallback(function ($uid) use ($id): array {
			$this->assertSame('alice', $uid);
			$this->assertFalse($this->db->inTransaction());
			++$this->membershipReads;
			return $this->memberships[$id];
		});
		$backend->method('groupExists')->willReturnCallback(function ($gid) use ($id): bool {
			$this->assertFalse($this->db->inTransaction());
			++$this->classifications;
			return in_array($gid, $this->exists[$id], true);
		});
		if ($name !== null) { $backend->method('getBackendName')->willReturn($name); }
		return $backend;
	}

	/** @param string $gid Group. @param string $state Lifecycle. @param string $module Module. @param string $op Operation. @return void Seed policy. */
	private function seed(string $gid = 'team', string $state = 'active', string $module = '_system', string $op = 'eligible'): void {
		$this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group($gid),
			'group_id' => $gid, 'module' => $module, 'operation' => $op]);
		$this->db->beginTransaction();
		try { $this->life->put($gid, $state, bin2hex(random_bytes(16)), '0'); $this->db->commit(); }
		catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
	}


	/** @return void Resolution preserves precedence, defaults and group eligibility with opaque rows. */
	public function testUnknownGrantDoesNotBreakKnownPolicy(): void {
		$this->backends = [$this->backend()];
		$this->seed();
		$this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group('team'), 'group_id' => 'team', 'module' => 'notes', 'operation' => 'edit']);
		$this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group('team'), 'group_id' => 'team', 'module' => 'future', 'operation' => 'read']);
		$context = $this->resolver->begin('alice');
		try {
			$this->assertTrue($context->eligible()->allowed);
			$this->assertTrue($context->resolveEffective('notes', 'edit')->allowed);
			$this->assertSame('group', $context->granted('notes', 'edit')->source);
			$this->assertTrue($context->granted('files', 'read')->allowed);
			$this->assertFalse($context->granted('notes', 'delete')->allowed);
		} finally {
			$context->close();
		}
		$this->data->user['alice']['mcp']['grant_notes_edit'] = '0';
		$context = $this->resolver->begin('alice');
		try {
			$this->assertFalse($context->granted('notes', 'edit')->allowed);
			$this->expectException(\InvalidArgumentException::class);
			$context->granted('future', 'read');
		} finally {
			$context->close();
		}
	}

	/** @return void Malformed data remains unavailable across the resolver boundary. */
	public function testCorruptionStillBlocksTheAffectedPolicy(): void {
		$this->backends = [$this->backend()];
		$this->seed();
		$this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group('team'), 'group_id' => 'team', 'module' => 'Files', 'operation' => 'read']);
		$this->expectException(PolicyUnavailable::class);
		$this->resolver->begin('alice');
	}
	/** @return void Multi-group and guest batches preserve existing source restrictions and unrelated users. */
	public function testBatchIncludesUnrelatedUsersAndGuestsWithoutGrantingUnknownRows(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn(true);
			return $user;
		});
		$this->backends = [];
		foreach (['Database' => ['team', 'other'], 'Guests' => ['guests']] as $name => $gids) {
			$backend = $this->createMockForIntersectionOfInterfaces([GroupInterface::class, INamedBackend::class]);
			$backend->method('getBackendName')->willReturn($name);
			$backend->method('groupExists')->willReturnCallback(static fn ($gid): bool => in_array($gid, $gids, true));
			$backend->method('getUserGroups')->willReturnCallback(static fn ($uid): array =>
				($name === 'Database' && $uid === 'alice') || ($name === 'Guests' && $uid === 'guest') ? $gids : []);
			$this->backends[] = $backend;
		}
		$this->resolver = $this->resolver();
		$this->seed();
		$this->seed('other', module: 'notes', op: 'edit');
		$this->seed('guests');
		foreach (['team', 'guests'] as $gid) {
			$this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group($gid), 'group_id' => $gid, 'module' => 'future', 'operation' => 'read']);
		}
		$contexts = $this->resolver->beginManyGrants(['alice', 'bob', 'guest']);
		try {
			$this->assertTrue($contexts['alice']->eligible()->allowed);
			$this->assertTrue($contexts['alice']->granted('notes', 'edit')->allowed);
			$this->assertFalse($contexts['bob']->eligible()->allowed);
			$this->assertFalse($contexts['bob']->granted('notes', 'edit')->allowed);
			$this->assertNotSame(true, $contexts['guest']->eligible()->allowed);
			$this->assertFalse($contexts['guest']->granted('notes', 'edit')->allowed);
		} finally {
			foreach ($contexts as $context) { $context->close(); }
		}
	}

}
