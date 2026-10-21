<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** The B2b composition mappers on SQLite: presence semantics, conditional writes and identity checks. */
final class AuthMappersTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
    }

    private static function gen(string $c): string {
        return str_repeat($c, 32);
    }

    /** @template T @param \Closure(): T $work @return T */
    private function inTransaction(\Closure $work): mixed {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function testAbsentUserIsGenerationOneUncutAndIssueCutAreMonotonic(): void {
        $users = new AuthUsersMapper($this->db);
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $users->generations('alice'));
        $this->assertSame('0', (string)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM mcp_auth_users'), 'reads never materialize a row');

        // A cut of the legacy generation needs no prior consent: (1, 1) kills every 1.0.8 credential of alice.
        $this->assertTrue($this->inTransaction(fn () => $users->cut('alice', '1')));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '1'], $users->generations('alice'));
        $this->assertSame('2', $this->inTransaction(fn () => $users->issue('alice')));
        $this->assertSame('3', $this->inTransaction(fn () => $users->issue('alice')));
        $this->assertFalse($this->inTransaction(fn () => $users->cut('alice', '1')), 'retrying an older cut changes nothing');
        $this->assertTrue($this->inTransaction(fn () => $users->cut('alice', '3')));
        $this->assertFalse($this->inTransaction(fn () => $users->cut('alice', '3')));
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '3'], $users->generations('alice'));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $users->generations('Alice'), 'ids are never case-folded');

        // A first consent of a user without a row issues generation 2, above every migrated credential.
        $this->assertSame('2', $this->inTransaction(fn () => $users->issue('bob')));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '0'], $users->generations('bob'));
        $this->assertFalse($this->inTransaction(fn () => $users->cut('carl', '0')));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $users->generations('carl'));
    }

    public function testCutAboveIssueAndExhaustedOrCorruptCountersFailClosed(): void {
        $users = new AuthUsersMapper($this->db);
        foreach (['2', '01', '-1', ''] as $cut) {
            try {
                $this->inTransaction(fn () => $users->cut('alice', $cut));
                $this->fail("Cut $cut accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->insertUser('alice', DecimalCounter::MAX, '0');
        try {
            $this->inTransaction(fn () => $users->issue('alice'));
            $this->fail('Exhausted generation wrapped');
        } catch (PolicyUnavailable) {
        }
        $this->insertUser('bob', 'abc', '0');
        $this->expectException(PolicyUnavailable::class);
        $users->generations('bob');
    }

    public function testWritesOutsideTheWriterTransactionAreRefused(): void {
        $life = new GroupLifeMapper($this->db);
        $events = new PolicyEventsMapper($this->db, $this->createMock(ITimeFactory::class));
        foreach ([
            fn () => (new AuthUsersMapper($this->db))->issue('alice'),
            fn () => (new AuthUsersMapper($this->db))->cut('alice', '1'),
            fn () => (new RecheckMapper($this->db))->consume('alice', IdentityKey::accountCause(), self::gen('a')),
            fn () => $life->put('staff', GroupLifeMapper::ACTIVE, self::gen('a'), '1'),
            fn () => $life->transition('staff', self::gen('a'), GroupLifeMapper::NEEDS_CONFIRM, self::gen('b')),
            fn () => $life->delete('staff', self::gen('a')),
            fn () => $events->append(self::gen('a'), PolicyEventsMapper::KIND_AUDIT, []),
        ] as $write) {
            try {
                $write();
                $this->fail('Write outside the transaction accepted');
            } catch (\LogicException) {
            }
        }
        foreach (['mcp_auth_users', 'mcp_auth_recheck', 'mcp_group_life', 'mcp_policy_events'] as $table) {
            $this->assertSame('0', (string)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM ' . $table), $table);
        }
    }

    public function testCausesAreOnePerUserAndCauseAndConsumedOnlyWithTheirGen(): void {
        $recheck = new RecheckMapper($this->db);
        $account = $recheck->record('alice', RecheckMapper::REASON_ACCOUNT, null, self::gen('a'), 100);
        $staff = $recheck->record('alice', RecheckMapper::REASON_GROUP, 'Staff', self::gen('b'), 101);
        $recheck->record('alice', RecheckMapper::REASON_GROUP, 'staff', self::gen('c'), 102);
        // The duplicate Before events of one native operation reuse its gen; a new operation replaces it.
        $recheck->record('alice', RecheckMapper::REASON_GROUP, 'Staff', self::gen('b'), 103);
        $recheck->record('bob', RecheckMapper::REASON_ACCOUNT, null, self::gen('d'), 104);
        $this->assertSame(IdentityKey::accountCause(), $account);
        $this->assertSame(IdentityKey::group('Staff'), $staff);

        $causes = $recheck->causes('alice');
        $this->assertCount(3, $causes);
        $byKey = array_column($causes, null, 'causeKey');
        $this->assertSame(['causeKey' => $staff, 'reason' => 'group_removal', 'groupId' => 'Staff', 'gen' => self::gen('b'), 'createdAt' => '101'], $byKey[$staff]);
        $this->assertNull($byKey[$account]['groupId']);
        $sorted = array_column($causes, 'causeKey');
        $copy = $sorted;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $sorted);

        $recheck->record('alice', RecheckMapper::REASON_GROUP, 'Staff', self::gen('e'), 105);
        $this->assertFalse($this->inTransaction(fn () => $recheck->consume('alice', $staff, self::gen('b'))), 'stale gen');
        $this->assertTrue($this->inTransaction(fn () => $recheck->consume('alice', $staff, self::gen('e'))));
        $this->assertFalse($this->inTransaction(fn () => $recheck->consume('alice', $staff, self::gen('e'))), 'already consumed');
        $left = array_column($recheck->causes('alice'), 'causeKey');
        $expected = [$account, IdentityKey::group('staff')];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $left);
        $this->assertCount(1, $recheck->causes('bob'));
    }

    public function testInvalidCausesAreRefusedAndCollisionsFailClosedBeforeAnyWrite(): void {
        $recheck = new RecheckMapper($this->db);
        foreach ([
            [RecheckMapper::REASON_ACCOUNT, 'Staff', self::gen('a')],
            [RecheckMapper::REASON_GROUP, null, self::gen('a')],
            ['other', null, self::gen('a')],
            [RecheckMapper::REASON_ACCOUNT, null, 'ABCDEF'],
            [RecheckMapper::REASON_GROUP, "a\0b", self::gen('a')],
        ] as [$reason, $gid, $gen]) {
            try {
                $recheck->record('alice', $reason, $gid, $gen, 1);
                $this->fail('Invalid cause accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        // A stored row whose original id differs from the requested one is a digest collision.
        $this->database->dbal->insert('mcp_auth_recheck', ['user_key' => IdentityKey::user('alice'), 'cause_key' => IdentityKey::accountCause(),
            'user_id' => 'mallory', 'reason' => 'account_delete', 'group_id' => null, 'gen' => self::gen('f'), 'created_at' => 1]);
        try {
            $recheck->record('alice', RecheckMapper::REASON_ACCOUNT, null, self::gen('a'), 2);
            $this->fail('Collision accepted');
        } catch (\UnexpectedValueException) {
        }
        $this->assertSame(self::gen('f'), $this->database->dbal->fetchOne('SELECT gen FROM mcp_auth_recheck'));
        $this->insertUser('bob', '1', '0', 'mallory');
        $this->expectException(\UnexpectedValueException::class);
        (new AuthUsersMapper($this->db))->generations('bob');
    }

    public function testARacingInsertOfTheSameCauseOutsideATransactionBecomesAnUpdate(): void {
        $recheck = new RecheckMapper($this->db);
        $competing = function (): void {
            $this->database->dbal->insert('mcp_auth_recheck', ['user_key' => IdentityKey::user('alice'), 'cause_key' => IdentityKey::accountCause(),
                'user_id' => 'alice', 'reason' => 'account_delete', 'group_id' => null, 'gen' => self::gen('a'), 'created_at' => 1]);
        };
        // The key is absent at the read; the competing insert of the same identity lands just before this insert.
        $this->database->beforeStatement = $competing;
        $recheck->record('alice', RecheckMapper::REASON_ACCOUNT, null, self::gen('b'), 2);
        $this->assertNull($this->database->beforeStatement, 'the competing insert must have run');
        $this->assertSame([self::gen('b')], array_column($recheck->causes('alice'), 'gen'));
    }

    public function testGroupLifecycleTransitionsOnlyFromTheGenItRead(): void {
        $life = new GroupLifeMapper($this->db);
        $this->assertNull($life->get('staff'));
        $this->inTransaction(fn () => $life->put('staff', GroupLifeMapper::ACTIVE, self::gen('a'), '4'));
        $this->inTransaction(fn () => $life->put('Staff', GroupLifeMapper::ACTIVE, self::gen('b'), '5'));
        $this->assertSame(['groupId' => 'staff', 'state' => 'active', 'gen' => self::gen('a'), 'policyRev' => '4'], $life->get('staff'));
        $this->assertSame(['Staff', 'staff'], array_column($life->forGroups(['Staff', 'none', 'staff', 'Staff']), 'groupId'));

        $this->assertFalse($this->inTransaction(fn () => $life->transition('staff', self::gen('c'), GroupLifeMapper::PENDING_DELETE, self::gen('d'))));
        $this->assertTrue($this->inTransaction(fn () => $life->transition('staff', self::gen('a'), GroupLifeMapper::PENDING_DELETE, self::gen('d'))));
        $this->assertSame(['pending_delete', self::gen('d'), '4'], array_values(array_slice($life->get('staff'), 1)));
        // A delayed delete conditioned on the old gen never removes the lifecycle saved since.
        $this->assertFalse($this->inTransaction(fn () => $life->delete('staff', self::gen('a'))));
        $this->inTransaction(fn () => $life->put('staff', GroupLifeMapper::ACTIVE, self::gen('e'), '9'));
        $this->assertFalse($this->inTransaction(fn () => $life->delete('staff', self::gen('d'))));
        $this->assertTrue($this->inTransaction(fn () => $life->delete('staff', self::gen('e'))));
        $this->assertNull($life->get('staff'));
        $this->assertNotNull($life->get('Staff'));

        foreach ([fn () => $life->put('x', 'deleted', self::gen('a'), '1'), fn () => $life->put('x', 'active', self::gen('a'), '-1'),
            fn () => $life->transition('Staff', self::gen('b'), 'active', self::gen('b'))] as $invalid) {
            try {
                $this->inTransaction($invalid);
                $this->fail('Invalid lifecycle accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->database->dbal->update('mcp_group_life', ['group_id' => 'other'], ['group_key' => IdentityKey::group('Staff')]);
        $this->expectException(\UnexpectedValueException::class);
        $life->get('Staff');
    }

    public function testPolicyEventsRoundTripInOrderAndRejectUnknownKindsOrIds(): void {
        $time = $this->createMock(ITimeFactory::class);
        $times = [20, 10, 30];
        $time->method('getTime')->willReturnCallback(function () use (&$times): int {
            return array_shift($times) ?? 40;
        });
        $events = new PolicyEventsMapper($this->db, $time);
        $this->inTransaction(function () use ($events): void {
            $events->append(self::gen('a') . '-1', PolicyEventsMapper::KIND_RECEIPT, ['cells' => [['uid' => 'Ålice', 'before' => null]]]);
            $events->append(self::gen('b'), PolicyEventsMapper::KIND_AUDIT, []);
            $events->append(self::gen('c') . '-2', PolicyEventsMapper::KIND_CLEANUP, ['cut' => '3']);
        });
        $this->assertSame([self::gen('b'), self::gen('a') . '-1', self::gen('c') . '-2'], array_column($events->pending(10), 'id'));
        $this->assertSame([self::gen('b')], array_column($events->pending(1), 'id'));
        $this->assertSame(['id' => self::gen('a') . '-1', 'kind' => 'receipt', 'status' => 'pending',
            'payload' => ['cells' => [['uid' => 'Ålice', 'before' => null]]], 'createdAt' => 20, 'attempts' => 0], $events->find(self::gen('a') . '-1'));
        $this->assertNull($events->find('missing'));
        foreach ([[self::gen('d'), 'intent'], ['nonce', 'audit'], [self::gen('d') . '-0', 'audit']] as [$id, $kind]) {
            try {
                $this->inTransaction(fn () => $events->append($id, $kind, []));
                $this->fail('Invalid event accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $this->inTransaction(fn () => $events->append(self::gen('b'), PolicyEventsMapper::KIND_AUDIT, []));
            $this->fail('Duplicate event id accepted');
        } catch (\OCP\DB\Exception $e) {
            $this->assertSame(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $e->getReason());
        }
        $this->assertCount(3, $events->pending(500));
    }

    /** A row of mallory stored under alice's digest: the simulated collision of contracts §1/T18. */
    private function collidingCause(string $gen, ?string $groupId = null): array {
        return ['user_key' => IdentityKey::user('alice'), 'cause_key' => $groupId === null ? IdentityKey::accountCause() : IdentityKey::group('staff'),
            'user_id' => $groupId === null ? 'mallory' : 'alice', 'reason' => $groupId === null ? 'account_delete' : 'group_removal',
            'group_id' => $groupId, 'gen' => $gen, 'created_at' => 1];
    }

    /** @return list<array<string, mixed>> */
    private function recheckRows(): array {
        return $this->database->dbal->fetchAllAssociative('SELECT user_id, group_id, gen FROM mcp_auth_recheck ORDER BY cause_key');
    }

    public function testACollidingRowInsertedBeforeTheInsertFailsClosedWithoutWriting(): void {
        // Alice's key is absent at the read; another session inserts mallory's row under the same digest first.
        $this->database->beforeStatement = fn () => $this->database->dbal->insert('mcp_auth_recheck', $this->collidingCause(self::gen('a')));
        try {
            (new RecheckMapper($this->db))->record('alice', RecheckMapper::REASON_ACCOUNT, null, self::gen('b'), 2);
            $this->fail('A colliding identity received the gen');
        } catch (\UnexpectedValueException) {
        }
        $this->assertNull($this->database->beforeStatement, 'the competing insert must have run');
        $this->assertSame([['user_id' => 'mallory', 'group_id' => null, 'gen' => self::gen('a')]], $this->recheckRows());
    }

    public function testARowReplacedByACollidingIdentityBeforeTheUpdateFailsClosedWithoutWriting(): void {
        $recheck = new RecheckMapper($this->db);
        $recheck->record('alice', RecheckMapper::REASON_ACCOUNT, null, self::gen('a'), 1);
        // Between the verified read of alice's row and the update, the row under that digest becomes mallory's.
        $this->database->beforeStatement = function (): void {
            $this->database->dbal->delete('mcp_auth_recheck', ['user_key' => IdentityKey::user('alice')]);
            $this->database->dbal->insert('mcp_auth_recheck', $this->collidingCause(self::gen('c')));
        };
        try {
            $recheck->record('alice', RecheckMapper::REASON_ACCOUNT, null, self::gen('b'), 2);
            $this->fail('A colliding identity received the gen');
        } catch (\UnexpectedValueException) {
        }
        $this->assertNull($this->database->beforeStatement);
        $this->assertSame([['user_id' => 'mallory', 'group_id' => null, 'gen' => self::gen('c')]], $this->recheckRows());
    }

    public function testAGroupCauseStoredUnderAnotherGroupIdFailsClosedOnRecordAndConsume(): void {
        // The digest of 'staff' holding the group id 'Staff': a collision on the group side of the cause.
        $this->database->dbal->insert('mcp_auth_recheck', $this->collidingCause(self::gen('a'), 'Staff'));
        $recheck = new RecheckMapper($this->db);
        foreach ([fn () => $recheck->record('alice', RecheckMapper::REASON_GROUP, 'staff', self::gen('b'), 2),
            fn () => $this->inTransaction(fn () => $recheck->consume('alice', IdentityKey::group('staff'), self::gen('a')))] as $write) {
            try {
                $write();
                $this->fail('A colliding group identity was written');
            } catch (\UnexpectedValueException) {
            }
        }
        $this->assertSame([['user_id' => 'alice', 'group_id' => 'Staff', 'gen' => self::gen('a')]], $this->recheckRows());
    }

    public function testConsumeRefusesARowOfAnotherIdentityWithoutDeletingIt(): void {
        $this->database->dbal->insert('mcp_auth_recheck', $this->collidingCause(self::gen('a')));
        try {
            $this->inTransaction(fn () => (new RecheckMapper($this->db))->consume('alice', IdentityKey::accountCause(), self::gen('a')));
            $this->fail('A row of another identity was consumed');
        } catch (\UnexpectedValueException) {
        }
        $this->assertSame([['user_id' => 'mallory', 'group_id' => null, 'gen' => self::gen('a')]], $this->recheckRows());
    }

    private function insertUser(string $uid, string $issue, string $revoked, ?string $storedId = null): void {
        $this->database->dbal->insert('mcp_auth_users', ['user_key' => IdentityKey::user($uid), 'user_id' => $storedId ?? $uid,
            'issue_seq' => $issue, 'revoked_below' => $revoked]);
    }
}
