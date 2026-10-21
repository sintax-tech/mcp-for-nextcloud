<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Tests\Integration\Support\NativePolicyServices;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUserManager;
use OCP\Server;
use RuntimeException;

/**
 * T3/T4 on the real core: an administrative command over several users is one transaction of official config
 * writes, and its undo restores the exact raw values, absence included, as seen from another session.
 */
final class AdminCommandsTest extends IntegrationTestCase {
    private const KEYS = ['grant_notes_edit', 'grant_deck_move', 'eligible'];
    private int $start = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->start = Server::get(ITimeFactory::class)->getTime();
        $this->clean();
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->clean();
        $qb = $this->official()->getQueryBuilder();
        $qb->delete(PolicyEventsMapper::TABLE)
            ->where($qb->expr()->gte('created_at', $qb->createNamedParameter($this->start)))
            ->andWhere($qb->expr()->in('kind', $qb->createNamedParameter([PolicyEventsMapper::KIND_RECEIPT, PolicyEventsMapper::KIND_AUDIT], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();
    }

    private function clean(): void {
        $config = Server::get(IConfig::class);
        foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
            foreach (self::KEYS as $key) {
                $config->deleteUserValue($uid, 'mcp', $key);
            }
            Server::get(IUserConfig::class)->clearCache($uid, false);
        }
    }

    /** @return array<string, array<string, string>> raw mcp values of both fixture users, read by another session */
    private function seenBy(IUserConfig $other): array {
        $seen = [];
        foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
            $other->clearCache($uid, false);
        }
        foreach (self::KEYS as $key) {
            // The row order is the database's (PostgreSQL differs): compare by user, not by position.
            $seen[$key] = $other->getValuesByUsers('mcp', $key, null, [Fixtures::ALICE, Fixtures::BOB]);
            ksort($seen[$key], SORT_STRING);
        }
        return $seen;
    }

    private function otherSession(): IUserConfig {
        $other = clone Server::get(IUserConfig::class);
        (new \ReflectionProperty($other, 'connection'))->setValue($other, $this->openIndependentConnection());
        return $other;
    }

    private function eventCount(): int {
        $qb = $this->official()->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*', 'n'))->from(PolicyEventsMapper::TABLE)->executeQuery();
        try {
            return (int)$result->fetchOne();
        } finally {
            $result->closeCursor();
        }
    }

    public function testAFailureInTheLastCellOfACommandChangesNoCellOfAnyUser(): void {
        $config = Server::get(IConfig::class);
        $config->setUserValue(Fixtures::BOB, 'mcp', 'eligible', '1');
        Server::get(IUserConfig::class)->clearCache(Fixtures::BOB, false);
        $other = $this->otherSession();
        $before = $this->seenBy($other);
        $state = Server::get(PolicyStateMapper::class);
        $revision = $state->state();
        $events = $this->eventCount();
        $db = $this->official();
        // The last cell takes eligibility away, so it revokes; the cut fails after every config write ran.
        $authUsers = new class($db) extends \OCA\Mcp\Db\AuthUsersMapper {
            public function cut(string $uid, string $cut): bool {
                throw new RuntimeException('Injected in the last cell');
            }
        };
        $writer = new NativePolicyServices($db, $state, Server::get(PolicyEventsMapper::class), $config, Server::get(IUserConfig::class),
            Server::get(IAppConfig::class), Server::get(IUserManager::class), Server::get(ITimeFactory::class), $authUsers,
            Server::get(\OCA\Mcp\Db\RecheckMapper::class), Server::get(\OCA\Mcp\Access\Policy\Events\PolicyEventProcessor::class), Server::get(\OCA\Mcp\Db\GroupGrantsMapper::class), Server::get(\OCA\Mcp\Db\GroupLifeMapper::class), Server::get(\OCA\Mcp\OAuth\OAuthStore::class));
        try {
            $writer->commands->grants->applyCells($revision['revision'], 'admin', 'grants.commands', [
                ['uid' => Fixtures::ALICE, 'key' => 'grant_notes_edit', 'value' => '1'],
                ['uid' => Fixtures::ALICE, 'key' => 'grant_deck_move', 'value' => '0'],
                ['uid' => Fixtures::BOB, 'key' => 'grant_notes_edit', 'value' => '1'],
                ['uid' => Fixtures::BOB, 'key' => 'eligible', 'value' => '0'],
            ]);
            $this->fail('Injected failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected in the last cell', $e->getMessage());
        }
        $this->assertFalse($db->inTransaction());
        $this->assertSame($before, $this->seenBy($other));
        $this->assertSame([Fixtures::BOB => '1'], $before['eligible']);
        $this->assertSame([], $before['grant_notes_edit']);
        $this->assertSame($revision, (new PolicyStateMapper($this->openIndependentConnection()))->state());
        $this->assertSame($events, $this->eventCount(), 'no receipt or audit survives the rollback');
        // The worker's own config objects read the old values too.
        $this->assertSame('', $config->getUserValue(Fixtures::ALICE, 'mcp', 'grant_notes_edit'));
        $this->assertSame('1', $config->getUserValue(Fixtures::BOB, 'mcp', 'eligible'));
    }

    public function testUndoRestoresAbsenceAndTheOldValueForAnotherSession(): void {
        $config = Server::get(IConfig::class);
        $config->setUserValue(Fixtures::BOB, 'mcp', 'grant_notes_edit', '0');
        Server::get(IUserConfig::class)->clearCache(Fixtures::BOB, false);
        $other = $this->otherSession();
        $writer = Server::get(PolicyTransaction::class);
        $state = Server::get(PolicyStateMapper::class);
        $from = $state->state()['revision'];

        $saved = Server::get(\OCA\Mcp\Access\Grants\Commands\GrantCommands::class)->applyCells($from, 'admin', 'grants.bulk', [
            ['uid' => Fixtures::ALICE, 'key' => 'grant_notes_edit', 'value' => '1'],
            ['uid' => Fixtures::BOB, 'key' => 'grant_notes_edit', 'value' => '1'],
        ]);
        $this->assertSame(2, $saved['changed']);
        $this->assertSame([Fixtures::ALICE => '1', Fixtures::BOB => '1'], $this->seenBy($other)['grant_notes_edit']); // alice < bob
        $receipt = Server::get(PolicyEventsMapper::class)->find((string)$saved['receiptId']);
        $this->assertSame([null, '0'], array_column($receipt['payload']['changes'], 'before'));

        $undone = Server::get(\OCA\Mcp\Access\Policy\Receipts\ReceiptUndo::class)->restoreReceipt((string)$saved['receiptId'], $saved['revision'], 'admin');
        $this->assertSame(2, $undone['changed']);
        $this->assertSame([Fixtures::BOB => '0'], $this->seenBy($other)['grant_notes_edit'], 'alice absent again, bob back to 0');
        $this->assertSame($undone['revision'], (new PolicyStateMapper($this->openIndependentConnection()))->state()['revision']);
        $this->assertNotSame($saved['revision'], $undone['revision']);
        // Applying the same receipt again finds a newer revision and touches nothing.
        try {
            Server::get(\OCA\Mcp\Access\Policy\Receipts\ReceiptUndo::class)->restoreReceipt((string)$saved['receiptId'], $saved['revision'], 'admin');
            $this->fail('A receipt was undone twice');
        } catch (\OCA\Mcp\Access\Policy\Transactions\PolicyConflict) {
        }
        $this->assertSame([Fixtures::BOB => '0'], $this->seenBy($other)['grant_notes_edit']);
    }
}
