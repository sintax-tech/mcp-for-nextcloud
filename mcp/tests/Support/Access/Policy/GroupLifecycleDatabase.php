<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Access\Policy;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Access\Grants\Groups\GroupLifecycle;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\NullLogger;

/**
 * The production writer, state, lifecycle and grant mappers on one SQLite connection, for the GID lifecycle tests.
 * The group source is a double: $exists says which groups a backend serves (absent = the read fails).
 */
trait GroupLifecycleDatabase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private PolicyServices $writer;
    private PolicyStateMapper $state;
    private GroupLifeMapper $life;
    private GroupGrantsMapper $grants;
    private RecheckMapper $rechecks;
    private GroupLifecycle $lifecycle;
    private \OCA\Mcp\Tests\Unit\InMemoryAppConfig $apps;
    /** @var array<string, bool> gid => served by a backend; a missing gid makes the existence read fail */
    private array $exists = [];
    /** @var (\Closure(): void)|null runs once inside the next existence read, in the external phase */
    private ?\Closure $duringExists = null;
    /** The clock every mapper and the writer read. */
    private int $now = 1_760_000_000;
    /** The user preferences and app config behind the writer. */
    private InMemoryConfig $configData;

    private function lifecycleDatabase(): void {
        $this->database = new SqliteDatabase($this, [new Version000500Date20260930000000(), new Version000700Date20261001000000(),
            new Version000800Date20261002000000(), new Version000800Date20261002010000(), new Version001002Date20261008000000(),
            new Version001100Date20261014000000(), new Version001100Date20261015000000($this->createMock(IDBConnection::class)),
            new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
        (new PolicyStateSeeder($this->db))->seed();
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $events = new PolicyEventsMapper($this->db, $time);
        $this->state = new PolicyStateMapper($this->db);
        $this->rechecks = new RecheckMapper($this->db);
        $this->configData = new InMemoryConfig();
        $this->writer = new PolicyServices(new PolicyTransaction($this->db, $this->state, $events, $this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class), new PolicyEventProcessor($events, new OAuthStore($this->db), new NullLogger(), $time)),
            $this->configData->mock($this), $this->createMock(IAppConfig::class), $this->createMock(IUserManager::class), $time, $this->state, $events, new AuthUsersMapper($this->db), $this->rechecks, new \OCA\Mcp\Db\GroupGrantsMapper($this->db), new \OCA\Mcp\Db\GroupLifeMapper($this->db), new \OCA\Mcp\OAuth\OAuthStore($this->db), new \OCA\Mcp\Db\ResourceScopeMapper($this->db));
        $this->life = new GroupLifeMapper($this->db);
        $this->grants = new GroupGrantsMapper($this->db);
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('exists')->willReturnCallback(function (string $gid): bool {
            $this->assertFalse($this->db->inTransaction(), 'no directory read under the mutex');
            if ($this->duringExists !== null) {
                [$hook, $this->duringExists] = [$this->duringExists, null];
                $hook();
            }
            return $this->exists[$gid] ?? throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('directory down');
        });
        $classifier->method('trusted')->willReturn(true);
        $this->apps = new \OCA\Mcp\Tests\Unit\InMemoryAppConfig();
        $this->lifecycle = new GroupLifecycle($this->writer->transaction, $this->life, $this->grants, $classifier, new NullLogger(), $this->apps->worker($this));
    }

    /**
     * Stores a group policy as a save would: grant rows and a lifecycle, written directly (test setup).
     *
     * @param list<array{string, string}> $grants module/operation pairs
     * @return string the lifecycle's gen
     */
    private function policy(string $gid, string $state = GroupLifeMapper::ACTIVE, string $policyRev = '0',
            array $grants = [['_system', 'eligible'], ['notes', 'edit']]): string {
        foreach ($grants as [$module, $operation]) {
            $this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group($gid), 'group_id' => $gid,
                'module' => $module, 'operation' => $operation]);
        }
        $gen = bin2hex(random_bytes(16));
        $this->database->dbal->insert('mcp_group_life', ['group_key' => IdentityKey::group($gid), 'group_id' => $gid,
            'state' => $state, 'gen' => $gen, 'policy_rev' => $policyRev]);
        return $gen;
    }

    /** An administrator's save of the group's policy after the given moment: new gen, active, post-commit revision. */
    private function save(string $gid): string {
        $gen = bin2hex(random_bytes(16));
        $this->writer->transaction->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($gid, $gen): bool {
            $this->life->put($gid, GroupLifeMapper::ACTIVE, $gen, DecimalCounter::increment($attempt->state()['revision']));
            return true;
        });
        return $gen;
    }

    /** @return list<string> module/operation of the group's stored rows */
    private function rows(string $gid): array {
        return array_map(static fn (array $row): string => $row['module'] . '/' . $row['operation'],
            $this->database->dbal->fetchAllAssociative('SELECT module, operation FROM mcp_group_grants WHERE group_key = ? ORDER BY module, operation', [IdentityKey::group($gid)]));
    }

    private function revision(): string {
        return $this->state->state()['revision'];
    }

    private function lockSeq(): string {
        return $this->state->state()['lock_seq'];
    }
}
