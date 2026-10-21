<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Access\Policy;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** Real state and scope tables with the transaction kernel for lifecycle and cleanup tests. */
final class ScopeStateFixture {
    public const HIDDEN_TAG = '77';

    public SqliteDatabase $database;
    public IDBConnection $db;
    public PolicyStateMapper $state;
    public PolicyEventsMapper $events;
    public ResourceScopeMapper $scopes;
    public InMemoryConfig $config;
    public InMemoryOAuthStore $oauth;
    public int $now = 1760000000;
    /** @var list<string> */
    public array $users = ['alice', 'bob', 'root'];
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

    }

    public function transaction(): PolicyTransaction {
        $time = $this->mock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        return new PolicyTransaction($this->db, $this->state, $this->events, $this->mock(IUserConfig::class),
            $this->mock(IAppConfig::class), new \OCA\Mcp\Access\Policy\Events\PolicyEventProcessor($this->events, $this->oauth, new \Psr\Log\NullLogger(), $time));
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
