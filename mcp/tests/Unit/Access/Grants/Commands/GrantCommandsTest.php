<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Commands;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Events\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\SnapshotInvalidated;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantCommandsTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private PolicyStateMapper $state;
    private \OCA\Mcp\Access\Policy\Receipts\ReceiptUndo $undo;
    private \OCA\Mcp\Access\Grants\Commands\ServiceCommands $service;

    protected function setUp(): void {
        $migration = new Version001100Date20261015000000($this->createMock(IDBConnection::class));
        $this->database = new SqliteDatabase($this, [$migration, new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
        $this->state = new PolicyStateMapper($this->db);
        (new Version001100Date20261015000000($this->db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
        // An app-owned scratch table models all local writes (groups, epochs and outbox) without core SQL.
        $this->database->dbal->executeStatement('CREATE TABLE unit_policy (id INTEGER PRIMARY KEY, value TEXT)');
    }

    private function commandWriter(InMemoryConfig $data, int &$now, ?\OCA\Mcp\Db\AuthUsersMapper $authUsers = null): \OCA\Mcp\Access\Grants\Commands\GrantCommands {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'ghost' ? null : $this->createMock(IUser::class));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(function () use (&$now): int { return $now; });
        $config = $data->mock($this);
        $apps = $this->createMock(IAppConfig::class);
        $apps->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $data->app[$app] ?? []));
        $events = new PolicyEventsMapper($this->db, $time);
        $transaction = new \OCA\Mcp\Access\Policy\Transactions\PolicyTransaction($this->db, $this->state, $events,
            $this->createMock(IUserConfig::class), $apps, new PolicyEventProcessor($events, new InMemoryOAuthStore(), new \Psr\Log\NullLogger(), $time));
        $holds = new \OCA\Mcp\Access\Grants\Groups\LifecycleHolds(new \OCA\Mcp\Db\GroupLifeMapper($this->db));
        $cuts = new \OCA\Mcp\OAuth\Revocation\CredentialCuts($authUsers ?? new \OCA\Mcp\Db\AuthUsersMapper($this->db),
            $this->state, new \OCA\Mcp\Db\RecheckMapper($this->db));
        $mutations = new \OCA\Mcp\Access\Grants\Commands\GrantMutations($config, $apps, $cuts, $holds);
        $inheritance = new \OCA\Mcp\Access\Grants\Commands\GrantInheritance();
        $this->service = new \OCA\Mcp\Access\Grants\Commands\ServiceCommands($transaction, $mutations);
        $known = new \OCA\Mcp\Access\Policy\PolicyUsers($users);
        $this->undo = new \OCA\Mcp\Access\Policy\Receipts\ReceiptUndo($transaction,
            new \OCA\Mcp\Access\Policy\Receipts\ReceiptStore($events, $time), $mutations, $inheritance, $known,
            new \OCA\Mcp\Access\ResourceScope\ScopeMutations(new \OCA\Mcp\Db\ResourceScopeMapper($this->db)),
            new \OCA\Mcp\Access\ResourceScope\ScopeActivation());
        return new \OCA\Mcp\Access\Grants\Commands\GrantCommands($transaction, $mutations, $inheritance, $known);
    }

    private function storedEvents(): array {
        return array_map(static fn (array $row): array => ['id' => $row['id'], 'kind' => $row['kind'], 'payload' => json_decode($row['payload'], true)],
            // Ids start with a random command id: order by time, then by the sequence within one command.
            $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_policy_events ORDER BY created_at, id'));
    }

    public function testSettersPreserveRawPresenceAndSkipOnlyIdenticalRawValues(): void {
        $data = new InMemoryConfig();
        $now = 1760000000;
        $writer = $this->commandWriter($data, $now);
        // Absence/default false -> explicit deny is a real raw change.
        $writer->setGrant('alice', 'notes', 'edit', false);
        $this->assertSame('0', $data->user['alice']['mcp']['grant_notes_edit']);
        $this->assertSame('1', $this->state->state()['revision']);
        $writer->setGrant('alice', 'notes', 'edit', false);
        $this->assertSame('1', $this->state->state()['revision']);
        $writer->resetOverride('alice', 'notes', 'edit');
        $this->assertArrayNotHasKey('grant_notes_edit', $data->user['alice']['mcp']);
        $this->assertSame('2', $this->state->state()['revision']);
        $writer->resetOverride('alice', 'notes', 'edit');
        $this->assertSame('2', $this->state->state()['revision']);
        $this->service->setGlobalEnabled(true);
        $this->service->setGlobalEnabled(true);
        $writer->setEligible('alice', true);
        $writer->setConnected('alice', true);
        $this->assertSame('5', $this->state->state()['revision']);
        $this->assertSame('1', $data->app['mcp']['service_enabled']);
        $this->assertSame('1', $data->user['alice']['mcp']['eligible']);
        $this->assertSame('1', $data->user['alice']['mcp']['connected']);
    }

    public function testUnknownUserAndInvalidCatalogFailBeforeAnyTransactionWrite(): void {
        $data = new InMemoryConfig();
        $now = 1760000000;
        $writer = $this->commandWriter($data, $now);
        foreach ([fn () => $writer->setEligible('ghost', true), fn () => $writer->setGrant('alice', 'files', 'delete', true)] as $command) {
            try {
                $command();
                $this->fail('Invalid input accepted');
            } catch (\InvalidArgumentException) {
            }
            $this->assertSame('0', $this->state->state()['lock_seq']);
            $this->assertFalse($this->db->inTransaction());
        }
    }

    public function testACommandCommitsItsReceiptAndAuditWithTheRevisionAndAFailureLeavesNeither(): void {
        $data = new InMemoryConfig();
        $now = 1760000000;
        // The last cell revokes bob: fail its cut, after every config write of the command.
        $authUsers = $this->createMock(\OCA\Mcp\Db\AuthUsersMapper::class);
        $authUsers->method('generations')->willReturn(['issue_seq' => '1', 'revoked_below' => '0']);
        $authUsers->method('cut')->willThrowException(new \RuntimeException('injected in the last cell'));
        try {
            $this->commandWriter($data, $now, $authUsers)->applyCells('0', 'root', 'grants.commands', [
                ['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1'],
                ['uid' => 'bob', 'key' => 'eligible', 'value' => '0'],
            ]);
            $this->fail('Injected failure swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected in the last cell', $e->getMessage());
        }
        // The database side rolled back: no revision, no receipt, no audit (the config double is not transactional).
        $this->assertSame(['revision' => '0', 'lock_seq' => '0', 'auth_epoch' => '0'], $this->state->state());
        $this->assertSame([], $this->storedEvents());

        $data = new InMemoryConfig();
        $result = $this->commandWriter($data, $now)->applyCells('0', 'root', 'grants.commands', [
            ['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1'],
            ['uid' => 'bob', 'key' => 'eligible', 'value' => '0'],
        ]);
        $this->assertSame('1', $result['revision']);
        $events = $this->storedEvents();
        // Revoking bob writes the cleanup of his cut in the same transaction, then the receipt and the audit.
        $this->assertSame([PolicyEventsMapper::KIND_CLEANUP, PolicyEventsMapper::KIND_RECEIPT, PolicyEventsMapper::KIND_AUDIT], array_column($events, 'kind'));
        $this->assertSame(['v' => 1, 'scope' => 'user', 'uid' => 'bob', 'cut' => '1', 'epoch' => null], $events[0]['payload']);
        $events = array_slice($events, 1);
        $this->assertSame($result['receiptId'], $events[0]['id']);
        $this->assertSame(['0', '1'], [$events[0]['payload']['fromRevision'], $events[0]['payload']['revision']]);
        $this->assertSame($result['receiptId'], $events[1]['payload']['receiptId']);
    }

    public function testUndoReadsTheStoredReceiptRestoresAbsenceAndExpiresAfterItsTtl(): void {
        $data = new InMemoryConfig();
        $data->user['bob']['mcp']['grant_deck_move'] = '0';
        $now = 1760000000;
        $writer = $this->commandWriter($data, $now);
        $saved = $writer->applyCells('0', 'root', 'grants.bulk', [
            ['uid' => 'alice', 'key' => 'grant_deck_move', 'value' => '1'],
            ['uid' => 'bob', 'key' => 'grant_deck_move', 'value' => '1'],
        ]);
        $now += \OCA\Mcp\Access\Policy\PolicyLimits::RECEIPT_TTL - 1; // the last second it is still valid
        $this->assertSame(['revision' => '2', 'changed' => 2], $this->undo->restoreReceipt($saved['receiptId'], '1', 'root'));
        $this->assertArrayNotHasKey('grant_deck_move', $data->user['alice']['mcp']);
        $this->assertSame('0', $data->user['bob']['mcp']['grant_deck_move']);
        $undo = $this->storedEvents()[2]['payload'];
        $this->assertSame(['grants.undo', $saved['receiptId'], null], [$undo['action'], $undo['undoOf'], $undo['receiptId']]);

        $again = $writer->applyCells('2', 'root', 'grants.update', [['uid' => 'alice', 'key' => 'eligible', 'value' => '1']]);
        $now += \OCA\Mcp\Access\Policy\PolicyLimits::RECEIPT_TTL;
        $this->expectException(\OCA\Mcp\Access\Policy\Receipts\ReceiptExpired::class);
        $this->undo->restoreReceipt($again['receiptId'], '3', 'root');
    }

    public function testACorruptStoredReceiptIsAStorageFailureNeverAPartialUndo(): void {
        $data = new InMemoryConfig();
        $now = 1760000000;
        $writer = $this->commandWriter($data, $now);
        $saved = $writer->applyCells('0', 'root', 'grants.update', [['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1']]);
        $this->database->dbal->update('mcp_policy_events', ['payload' => json_encode(['v' => 1, 'actor' => 'root'])], ['id' => $saved['receiptId']]);
        try {
            $this->undo->restoreReceipt($saved['receiptId'], '1', 'root');
            $this->fail('Corrupt receipt accepted');
        } catch (PolicyUnavailable) {
        }
        $this->assertSame('1', $data->user['alice']['mcp']['grant_notes_edit']);
        $this->assertSame('1', $this->state->state()['revision']);
    }

    public function testAnAuditEventIdIsNeverAcceptedAsAReceipt(): void {
        $data = new InMemoryConfig();
        $now = 1760000000;
        $writer = $this->commandWriter($data, $now);
        $saved = $writer->applyCells('0', 'root', 'grants.update', [['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1']]);
        $audit = $this->storedEvents()[1]['id'];
        $this->assertNotSame($saved['receiptId'], $audit);
        $this->expectException(\OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound::class);
        $this->undo->restoreReceipt($audit, '1', 'root');
    }

}
