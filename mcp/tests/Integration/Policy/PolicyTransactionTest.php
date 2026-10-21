<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Migration\PolicyStateSeedStep;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Tests\Integration\Support\NativePolicyServices;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** T1/T2 on the real core: official config writes, two independent sessions, reused worker caches. */
final class PolicyTransactionTest extends IntegrationTestCase {
    private const SET_KEY = 'grant_notes_edit';
    private const DELETE_KEY = 'grant_deck_move';
    private string $gid = '';
    /** @var list<string> */
    private array $eventIds = [];

    protected function tearDown(): void {
        parent::tearDown();
        $config = Server::get(IConfig::class);
        foreach ([self::SET_KEY, self::DELETE_KEY] as $key) {
            $config->deleteUserValue(Fixtures::ALICE, 'mcp', $key);
        }
        $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
        Server::get(IUserConfig::class)->clearCache(Fixtures::ALICE, false);
        Server::get(IAppConfig::class)->clearCache(false);
        foreach ($this->eventIds as $id) {
            $qb = $this->official()->getQueryBuilder();
            $qb->delete('mcp_policy_events')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
        }
        if ($this->gid !== '') {
            $qb = $this->official()->getQueryBuilder();
            $qb->delete('mcp_group_grants')->where($qb->expr()->eq('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $this->gid))))->executeStatement();
        }
    }

    public static function failurePoints(): array {
        return array_map(static fn ($point): array => [$point], ['set', 'delete', 'app', 'group', 'cas', 'outbox', 'none']);
    }

    #[DataProvider('failurePoints')]
    public function testAtomicityAndCacheRecoveryAfterEveryWrite(string $failure): void {
        $db = $this->official();
        $second = $this->openIndependentConnection();
        $this->assertNotSame($this->sessionId($db), $this->sessionId($second));
        $config = Server::get(IConfig::class);
        $users = Server::get(IUserConfig::class);
        $apps = Server::get(IAppConfig::class);
        // Verify the actual core dependency instead of assuming the container shares the connection.
        $this->assertSame($db, (new \ReflectionProperty($users, 'connection'))->getValue($users));
        $this->assertSame($db, (new \ReflectionProperty($apps, 'connection'))->getValue($apps));
        $config->setUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY, '0');
        $config->setUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY, '1');
        $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, '0');
        $users->clearCache(Fixtures::ALICE, false);
        $apps->clearCache(false);
        $this->assertSame('0', $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY));
        $this->assertSame('1', $config->getUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
        $typeBefore = $users->getValueType(Fixtures::ALICE, 'mcp', self::SET_KEY);
        $this->gid = 'mcp-it-policy-' . bin2hex(random_bytes(8));
        $this->assertSame($db, (new \ReflectionProperty(Server::get(OAuthStore::class), 'db'))->getValue(Server::get(OAuthStore::class)));
        $state = new PolicyStateMapper($db);
        $before = $state->state();

        // Test-only core clones bind to the independent session. All reads still use official config APIs;
        // AllConfig itself resolves services statically and therefore cannot represent a second session.
        $otherUsers = clone $users;
        (new \ReflectionProperty($otherUsers, 'connection'))->setValue($otherUsers, $second);
        $otherApps = clone $apps;
        (new \ReflectionProperty($otherApps, 'connection'))->setValue($otherApps, $second);
        $eventId = null;
        $otherEvents = new PolicyEventsMapper($second, Server::get(ITimeFactory::class));
        $observeOld = function () use ($otherUsers, $otherApps, $second, $before, &$eventId, $otherEvents): void {
            if ($eventId !== null) {
                $this->assertNull($otherEvents->find($eventId));
            }
            $otherUsers->clearCache(Fixtures::ALICE, false);
            $otherApps->clearCache(false);
            $this->assertSame([Fixtures::ALICE => '0'], $otherUsers->getValuesByUsers('mcp', self::SET_KEY, null, [Fixtures::ALICE]));
            $this->assertSame([Fixtures::ALICE => '1'], $otherUsers->getValuesByUsers('mcp', self::DELETE_KEY, null, [Fixtures::ALICE]));
            $this->assertSame('0', $otherApps->getValueString('mcp', GrantPolicy::SERVICE_KEY));
            $this->assertSame([['groupId' => $this->gid, 'grants' => []]], (new GroupGrantsMapper($second))->forGroups([$this->gid]));
            $this->assertSame($before, (new PolicyStateMapper($second))->state());
        };
        $after = function (string $point) use ($observeOld, $failure): void {
            $observeOld();
            if ($failure === $point) {
                throw new RuntimeException('Injected after ' . $point);
            }
        };
        $mapper = new class($db, $after) extends PolicyStateMapper {
            public function __construct(IDBConnection $db, private \Closure $after) { parent::__construct($db); }
            public function compareAndSet(string $expected): void {
                parent::compareAndSet($expected);
                ($this->after)('cas');
            }
        };
        $events = new class($db, Server::get(ITimeFactory::class), $after) extends PolicyEventsMapper {
            public function __construct(IDBConnection $db, ITimeFactory $time, private \Closure $after) { parent::__construct($db, $time); }
            public function append(string $id, string $kind, array $payload): void {
                parent::append($id, $kind, $payload);
                ($this->after)('outbox');
            }
        };
        $writer = new NativePolicyServices($db, $mapper, $events, $config, $users, $apps, Server::get(IUserManager::class), Server::get(\OCP\AppFramework\Utility\ITimeFactory::class), Server::get(\OCA\Mcp\Db\AuthUsersMapper::class), Server::get(\OCA\Mcp\Db\RecheckMapper::class), Server::get(\OCA\Mcp\Access\Policy\Events\PolicyEventProcessor::class), Server::get(\OCA\Mcp\Db\GroupGrantsMapper::class), Server::get(\OCA\Mcp\Db\GroupLifeMapper::class), Server::get(\OCA\Mcp\OAuth\OAuthStore::class));
        try {
            $result = $writer->commands->transaction->runAdmin($before['revision'], [Fixtures::ALICE], function () use ($db): void {
                $this->assertFalse($db->inTransaction());
                $this->assertNotNull(Server::get(IUserManager::class)->get(Fixtures::ALICE));
            }, function ($snapshot, PolicyAttempt $attempt) use ($config, $db, $after, &$eventId): bool {
                $current = $attempt->state();
                $commandId = $attempt->commandId();
                $outbox = $attempt->outbox();
                $eventId = $outbox->add(PolicyEventsMapper::KIND_AUDIT, ['test' => 'policy-transaction']);
                $this->eventIds[] = $eventId;
                $config->setUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY, '1');
                $after('set');
                $config->deleteUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY);
                $after('delete');
                $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, '1');
                $after('app');
                $qb = $db->getQueryBuilder();
                $qb->insert('mcp_group_grants')
                    ->setValue('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $this->gid)))
                    ->setValue('group_id', $qb->createNamedParameter($this->gid))
                    ->setValue('module', $qb->createNamedParameter('notes'))
                    ->setValue('operation', $qb->createNamedParameter('edit'))->executeStatement();
                $after('group');
                return true;
            });
            $this->assertSame('none', $failure);
            $this->assertTrue($result['changed']);
        } catch (RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'Injected after ')) {
                throw $e;
            }
            $this->assertNotSame('none', $failure);
        }
        $this->assertFalse($db->inTransaction());
        if ($failure !== 'none') {
            $observeOld();
            $this->assertSame($before, $state->state());
            // The exact IConfig/IUserConfig/IAppConfig objects used before and during the failed transaction.
            $this->assertSame('0', $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY));
            $this->assertSame('1', $config->getUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
            $this->assertTrue($users->hasKey(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
            $this->assertSame('0', $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY));
        } else {
            $otherUsers->clearCache(Fixtures::ALICE, false);
            $otherApps->clearCache(false);
            $this->assertSame([Fixtures::ALICE => '1'], $otherUsers->getValuesByUsers('mcp', self::SET_KEY, null, [Fixtures::ALICE]));
            $this->assertSame([], $otherUsers->getValuesByUsers('mcp', self::DELETE_KEY, null, [Fixtures::ALICE]));
            $this->assertSame('1', $otherApps->getValueString('mcp', GrantPolicy::SERVICE_KEY));
            $this->assertNotSame($before['revision'], (new PolicyStateMapper($second))->state()['revision']);
            $this->assertSame([['groupId' => $this->gid, 'grants' => ['notes' => ['edit']]]], (new GroupGrantsMapper($second))->forGroups([$this->gid]));
            $this->assertSame(['test' => 'policy-transaction'], $otherEvents->find($eventId)['payload']);
            $this->assertSame($result['commandId'] . '-1', $eventId);
            $this->assertSame('1', $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY));
            $this->assertFalse($users->hasKey(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
        }
        $this->assertSame($typeBefore, $users->getValueType(Fixtures::ALICE, 'mcp', self::SET_KEY));
    }

    public function testTwoWritersRejectStaleRevisionAndRepeatedSeedPreservesIt(): void {
        $state = Server::get(PolicyStateMapper::class);
        $writer = Server::get(PolicyTransaction::class);
        $revision = $state->state()['revision'];
        $writer->runAdmin($revision, [], fn () => null, fn () => true);
        $after = $state->state();
        (new Version001100Date20261015000000($this->official()))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
        // The step the server runs on install and after a migration, over the same live row.
        $step = new PolicyStateSeedStep(new PolicyStateSeeder($this->official()));
        $step->run($this->createMock(IOutput::class));
        $this->assertSame($after, $state->state());
        try {
            $writer->runAdmin($revision, [], fn () => null, fn () => $this->fail('Stale writer applied'));
            $this->fail('Stale writer accepted');
        } catch (PolicyConflict) {
        }
        $this->assertSame($after, (new PolicyStateMapper($this->openIndependentConnection()))->state());
    }

    /**
     * The setters every caller uses, end to end on the core: change() reads presence through
     * IConfig::getUserValueForUsers (and IAppConfig::hasKey for the service switch) and must tell a no-op from a write
     * exactly as IUserConfig::getValuesByUsers would, including right after a read has filled the worker caches.
     */
    public function testRealSettersAdvanceRevisionOnlyForRawChangesAndRollBackInsideChange(): void {
        $config = Server::get(IConfig::class);
        $users = Server::get(IUserConfig::class);
        $apps = Server::get(IAppConfig::class);
        $state = Server::get(PolicyStateMapper::class);
        $writer = Server::get(PolicyTransaction::class);
        $policy = Server::get(GrantPolicy::class);
        $second = $this->openIndependentConnection();
        $otherUsers = clone $users;
        (new \ReflectionProperty($otherUsers, 'connection'))->setValue($otherUsers, $second);
        $otherApps = clone $apps;
        (new \ReflectionProperty($otherApps, 'connection'))->setValue($otherApps, $second);
        $config->deleteUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY);
        $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);

        // Runs one setter after warming every cache it reads, then checks the revision step and what another
        // session sees for the grant (null = absent) and for the service switch.
        $step = function (string $label, \Closure $command, bool $advances, ?string $grant, ?string $service) use ($config, $state, $otherUsers, $otherApps): void {
            $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY);
            $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY);
            $before = $state->state()['revision'];
            $command();
            $this->assertSame($advances ? (string)((int)$before + 1) : $before, $state->state()['revision'], $label);
            $otherUsers->clearCache(Fixtures::ALICE, false);
            $otherApps->clearCache(false);
            $this->assertSame($grant === null ? [] : [Fixtures::ALICE => $grant],
                $otherUsers->getValuesByUsers('mcp', self::SET_KEY, null, [Fixtures::ALICE]), $label);
            $this->assertSame($service !== null, $otherApps->hasKey('mcp', GrantPolicy::SERVICE_KEY), $label);
            if ($service !== null) {
                $this->assertSame($service, $otherApps->getValueString('mcp', GrantPolicy::SERVICE_KEY), $label);
            }
        };

        $step('absent -> 1', fn () => $policy->setGrant(Fixtures::ALICE, 'notes', 'edit', true), true, '1', null);
        $config->setUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY, '0');
        $step('0 -> 1', fn () => $policy->setGrant(Fixtures::ALICE, 'notes', 'edit', true), true, '1', null);
        $step('1 -> 1', fn () => $policy->setGrant(Fixtures::ALICE, 'notes', 'edit', true), false, '1', null);
        $step('reset present', fn () => Server::get(\OCA\Mcp\Access\Grants\Commands\GrantCommands::class)->resetOverride(Fixtures::ALICE, 'notes', 'edit'), true, null, null);
        $step('reset absent', fn () => Server::get(\OCA\Mcp\Access\Grants\Commands\GrantCommands::class)->resetOverride(Fixtures::ALICE, 'notes', 'edit'), false, null, null);
        $step('service absent -> 1', fn () => $policy->setGlobalEnabled(true), true, null, '1');
        $step('service 1 -> 1', fn () => $policy->setGlobalEnabled(true), false, null, '1');

        // A failure inside change(), after its official config write: everything rolls back, on both sessions.
        $config->setUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY, '1');
        $before = $state->state();
        $db = $this->official();
        $mapper = new class($db) extends PolicyStateMapper {
            public function compareAndSet(string $expected): void {
                parent::compareAndSet($expected);
                throw new RuntimeException('Injected inside change()');
            }
        };
        $services = new NativePolicyServices($db, $mapper, Server::get(PolicyEventsMapper::class), $config, $users, $apps,
            Server::get(IUserManager::class), Server::get(\OCP\AppFramework\Utility\ITimeFactory::class), Server::get(\OCA\Mcp\Db\AuthUsersMapper::class), Server::get(\OCA\Mcp\Db\RecheckMapper::class), Server::get(\OCA\Mcp\Access\Policy\Events\PolicyEventProcessor::class), Server::get(\OCA\Mcp\Db\GroupGrantsMapper::class), Server::get(\OCA\Mcp\Db\GroupLifeMapper::class), Server::get(\OCA\Mcp\OAuth\OAuthStore::class));
        $failing = new GrantPolicy($config, $users, $services->commands->grants, $services->commands->service,
            Server::get(\OCA\Mcp\Access\Policy\Resolution\PolicyResolver::class), $services->commands->transaction);
        try {
            $failing->setGrant(Fixtures::ALICE, 'notes', 'edit', false);
            $this->fail('Injected failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected inside change()', $e->getMessage());
        }
        $this->assertFalse($db->inTransaction());
        $this->assertSame($before, $state->state());
        $this->assertSame($before, (new PolicyStateMapper($second))->state());
        $otherUsers->clearCache(Fixtures::ALICE, false);
        $this->assertSame([Fixtures::ALICE => '1'], $otherUsers->getValuesByUsers('mcp', self::SET_KEY, null, [Fixtures::ALICE]));
        $this->assertTrue($policy->granted(Fixtures::ALICE, 'notes', 'edit'));
    }

    /** The seed on a live row takes the unique-violation path on every real database, from either session. */
    public function testSeedOverALiveRowIsANoOpFromAnySession(): void {
        $state = Server::get(PolicyStateMapper::class);
        Server::get(PolicyTransaction::class)->runInternal([], fn () => null, fn () => true);
        $after = $state->state();
        (new PolicyStateSeeder($this->official()))->seed();
        (new PolicyStateSeeder($this->openIndependentConnection()))->seed();
        $this->assertFalse($this->official()->inTransaction());
        $this->assertSame($after, $state->state());
    }
}
