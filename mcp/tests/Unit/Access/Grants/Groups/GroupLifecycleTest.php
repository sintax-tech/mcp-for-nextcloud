<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Groups;
use OCA\Mcp\Tests\Support\Access\Policy\GroupLifecycleDatabase;

use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\OAuth\Revocation\CauseClassifier;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\OAuth\Revocation\DeferredQueue;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The GID lifecycle of contracts §3.3.5 on SQLite, with the production writer and mappers: every final transition is
 * a writer command that advances the revision when it changes something; the pre-event pending_delete is the one that
 * neither takes the mutex nor moves the revision. T6 (unit part) and T19 (deferred group: needs_confirm, nothing
 * deleted).
 */
final class GroupLifecycleTest extends TestCase {
    use GroupLifecycleDatabase;

    protected function setUp(): void {
        $this->lifecycleDatabase();
    }

    public function testTheDeletionsPreEventMarksPendingWithoutTheMutexOrTheRevision(): void {
        $gen = $this->policy('staff');
        [$revision, $lock] = [$this->revision(), $this->lockSeq()];
        $next = bin2hex(random_bytes(16));
        $this->assertTrue($this->life->markPendingDelete('staff', $gen, $next));
        $this->assertSame(['groupId' => 'staff', 'state' => 'pending_delete', 'gen' => $next, 'policyRev' => '0'], $this->life->get('staff'));
        $this->assertSame([$revision, $lock], [$this->revision(), $this->lockSeq()], 'no mutex, no revision');
        // Conditioned on the gen it read: a stale gen moves nothing.
        $this->assertFalse($this->life->markPendingDelete('staff', $gen, bin2hex(random_bytes(16))));
        $this->assertSame($next, $this->life->get('staff')['gen']);
        // A new deletion after a failed one renews the gen.
        $renewed = bin2hex(random_bytes(16));
        $this->assertTrue($this->life->markPendingDelete('staff', $next, $renewed));
        $this->assertSame($renewed, $this->life->get('staff')['gen']);
    }

    public function testThePreEventTransitionNeverRunsInsideATransaction(): void {
        $gen = $this->policy('staff');
        $this->database->dbal->beginTransaction();
        try {
            $this->life->markPendingDelete('staff', $gen, bin2hex(random_bytes(16)));
            $this->fail('A pre-event transition ran inside a transaction');
        } catch (\LogicException) {
        } finally {
            $this->database->dbal->rollBack();
        }
        $this->assertSame($gen, $this->life->get('staff')['gen']);
    }

    public function testADeletionPurgesOnlyWithTheGenItsBeforeWrote(): void {
        $gen = $this->policy('staff');
        $this->policy('team');
        $pending = bin2hex(random_bytes(16));
        $this->life->markPendingDelete('staff', $gen, $pending);
        $revision = $this->revision();

        $this->assertFalse($this->lifecycle->deleted('staff', $gen), 'the gen before the transition is stale');
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame($revision, $this->revision());

        $this->assertTrue($this->lifecycle->deleted('staff', $pending));
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame(\OCA\Mcp\Access\Policy\DecimalCounter::increment($revision), $this->revision(), 'one purge, one revision');
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('team'), 'another group is untouched');
        $this->assertFalse($this->lifecycle->deleted('staff', $pending), 'a repeated event is a no-op');
    }

    /** T6: a late GroupDeletedEvent with the old gen never removes a policy saved since (the save got a new gen). */
    public function testALateDeletedEventNeverRemovesAPolicySavedSince(): void {
        $gen = $this->policy('staff');
        $pending = bin2hex(random_bytes(16));
        $this->life->markPendingDelete('staff', $gen, $pending);
        $saved = $this->save('staff');
        $revision = $this->revision();
        $this->assertFalse($this->lifecycle->deleted('staff', $pending));
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame(['state' => 'active', 'gen' => $saved], array_intersect_key($this->life->get('staff'), ['state' => 1, 'gen' => 1]));
        $this->assertSame($revision, $this->revision());
    }

    /** T6: policy_rev <= R_c is an earlier group's policy (purged); > R_c was saved after the creation began (kept). */
    public function testACreationPurgesAPolicySavedBeforeItBeganAndKeepsOneSavedAfter(): void {
        $this->policy('old', policyRev: '3');
        $this->policy('new', policyRev: '5');
        $revision = $this->revision();
        $this->lifecycle->created('old', '3');
        $this->assertSame([], $this->rows('old'));
        $this->assertNull($this->life->get('old'));
        $this->assertSame(\OCA\Mcp\Access\Policy\DecimalCounter::increment($revision), $this->revision());

        $revision = $this->revision();
        $this->lifecycle->created('new', '4');
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('new'));
        $this->assertSame('active', $this->life->get('new')['state']);
        $this->assertSame($revision, $this->revision(), 'nothing changed, nothing advanced');
    }

    /** A policy saved after the creation's Before read R_c, through the writer: its post-commit revision is > R_c. */
    public function testAPolicySavedBetweenACreationsBeforeAndItsEventIsKept(): void {
        $this->policy('staff', policyRev: '0');
        $rc = $this->revision();
        $this->save('staff');
        $this->lifecycle->created('staff', $rc);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame('active', $this->life->get('staff')['state']);
    }

    public function testACreationWithoutAUsableRevisionSuspendsThePolicyAndDeletesNothing(): void {
        $this->policy('staff');
        $revision = $this->revision();
        $this->lifecycle->created('staff', null);
        $this->assertSame('needs_confirm', $this->life->get('staff')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame(\OCA\Mcp\Access\Policy\DecimalCounter::increment($revision), $this->revision());
        $this->lifecycle->created('staff', null);
        $this->assertSame(\OCA\Mcp\Access\Policy\DecimalCounter::increment($revision), $this->revision(), 'already suspended: no-op');
    }

    public function testAGroupWithoutLifecycleNeverTakesTheMutex(): void {
        $lock = $this->lockSeq();
        $this->lifecycle->created('plain', '0');
        $this->lifecycle->created('plain', null);
        $this->assertFalse($this->lifecycle->needsConfirm('plain'));
        $this->assertSame(0, $this->lifecycle->reconcile());
        $this->assertSame($lock, $this->lockSeq());
    }

    /** T6: the job purges a pending deletion of a group that is gone and suspends one whose group still exists. */
    public function testTheJobPurgesAGoneGroupAndSuspendsOneThatStillExists(): void {
        foreach (['gone', 'failed', 'unreadable', 'kept'] as $gid) {
            $gen = $this->policy($gid);
            if ($gid !== 'kept') {
                $this->life->markPendingDelete($gid, $gen, bin2hex(random_bytes(16)));
            }
        }
        $this->exists = ['gone' => false, 'failed' => true, 'kept' => true];
        $revision = $this->revision();
        $this->assertSame(2, $this->lifecycle->reconcile());
        $this->assertNull($this->life->get('gone'));
        $this->assertSame([], $this->rows('gone'));
        $this->assertSame('needs_confirm', $this->life->get('failed')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('failed'), 'a failed deletion keeps its rows');
        $this->assertSame('pending_delete', $this->life->get('unreadable')['state'], 'a failed read decides nothing');
        $this->assertSame('active', $this->life->get('kept')['state']);
        $this->assertSame(\OCA\Mcp\Access\Policy\DecimalCounter::increment(\OCA\Mcp\Access\Policy\DecimalCounter::increment($revision)), $this->revision());
        // Idempotent: the decided ones are not pending any more.
        $this->exists['unreadable'] = false;
        $this->assertSame(1, $this->lifecycle->reconcile());
        $this->assertSame(0, $this->lifecycle->reconcile());
    }

    public function testTheJobDecidesOnlyTheGenItRead(): void {
        $gen = $this->policy('staff');
        $this->life->markPendingDelete('staff', $gen, bin2hex(random_bytes(16)));
        $this->exists = ['staff' => false];
        // An administrator saves the group between the job's read and its command.
        $this->duringExists = function (): void { $this->save('staff'); };
        $this->assertSame(0, $this->lifecycle->reconcile());
        $this->assertSame('active', $this->life->get('staff')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
    }

    /** Ids are compared byte for byte before any write or delete: a digest collision fails closed. */
    public function testADigestCollisionFailsClosedBeforeAnyDelete(): void {
        $gen = $this->policy('staff');
        $this->database->dbal->executeStatement('DELETE FROM mcp_group_grants');
        // A grant row under staff's key that belongs to another id.
        $this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group('staff'), 'group_id' => 'Staff',
            'module' => 'notes', 'operation' => 'edit']);
        $pending = bin2hex(random_bytes(16));
        $this->life->markPendingDelete('staff', $gen, $pending);
        $revision = $this->revision();
        try {
            $this->lifecycle->deleted('staff', $pending);
            $this->fail('A colliding row was deleted');
        } catch (\UnexpectedValueException) {
        }
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame($pending, $this->life->get('staff')['gen']);
        $this->assertSame(1, (int)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM mcp_group_grants'));
        $this->assertSame($revision, $this->revision());

        // The lifecycle row itself under another id: the pre-event transition refuses before any update.
        $this->database->dbal->insert('mcp_group_life', ['group_key' => IdentityKey::group('team'), 'group_id' => 'Team',
            'state' => 'active', 'gen' => $gen, 'policy_rev' => '0']);
        try {
            $this->life->markPendingDelete('team', $gen, bin2hex(random_bytes(16)));
            $this->fail('A colliding lifecycle was updated');
        } catch (\UnexpectedValueException) {
        }
        $this->assertSame('active', $this->database->dbal->fetchOne('SELECT state FROM mcp_group_life WHERE group_id = ?', ['Team']));
    }

    /**
     * T19 (group part): a deletion or creation seen inside a caller's transaction is processed at the next safe point
     * as needs_confirm, committed or not; nothing is deleted and no GroupDeletedEvent is simulated.
     */
    public function testADeferredGroupSignalSuspendsThePolicyAtTheNextSafePointAndDeletesNothing(): void {
        $this->policy('staff');
        $registered = [];
        $queue = new class($registered) extends DeferredQueue {
            public function __construct(private array &$registered) {}
            protected function register(callable $onShutdown): void { $this->registered[] = $onShutdown; }
        };
        $processor = new CauseProcessor($this->writer->credentials, $this->rechecks, $this->createMock(CauseClassifier::class), $queue,
            $this->db, new NullLogger(), (new InMemoryAppConfig())->worker($this), $this->lifecycle);
        foreach ([true, false] as $commit) {
            $this->database->dbal->beginTransaction();
            $processor->deferGroup('staff');
            $processor->flush();
            $this->assertSame('active', $this->life->get('staff')['state'], 'nothing runs inside the transaction');
            $commit ? $this->database->dbal->commit() : $this->database->dbal->rollBack();
            $processor->flush();
            $this->assertSame('needs_confirm', $this->life->get('staff')['state']);
            $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
            $this->database->dbal->update('mcp_group_life', ['state' => 'active'], ['group_key' => IdentityKey::group('staff')]);
        }
        $this->assertCount(1, $registered, 'the shutdown callback is registered once');
    }

    /**
     * The holders a group deletion re-checks: owners of a live code or grant (not expired, current epoch), once each,
     * with their highest generation.
     */
    public function testTheLiveHoldersAreOwnersOfUnexpiredRowsOfTheCurrentEpochWithTheirHighestGeneration(): void {
        $rows = [
            ['mcp_oauth_tokens', 'alice', 2, 'refresh_expires', 200, 0],
            ['mcp_oauth_tokens', 'alice', 5, 'refresh_expires', 200, 0],
            ['mcp_oauth_codes', 'alice', 3, 'expires_at', 200, 0],
            ['mcp_oauth_codes', 'bob', 4, 'expires_at', 200, 0],
            ['mcp_oauth_tokens', 'carol', 9, 'refresh_expires', 99, 0],
            ['mcp_oauth_tokens', 'dave', 9, 'refresh_expires', 200, 1],
        ];
        foreach ($rows as $i => [$table, $uid, $gen, $expiry, $until, $epoch]) {
            $row = [];
            foreach (array_column($this->tableColumns($table), 'name') as $column) {
                $row[$column] = match ($column) {
                    'id' => $i + 1,
                    'user_id' => $uid,
                    'auth_gen' => $gen,
                    'auth_global_epoch' => $epoch,
                    $expiry => $until,
                    default => $column . '-' . $i,
                };
            }
            $this->database->dbal->insert($table, $row);
        }
        $holders = array_values((new \OCA\Mcp\OAuth\OAuthStore($this->db))->liveHolders(100, '0'));
        usort($holders, static fn (array $a, array $b): int => strcmp($a['uid'], $b['uid']));
        $this->assertSame([['uid' => 'alice', 'gen' => '5'], ['uid' => 'bob', 'gen' => '4']], $holders);
    }

    /**
     * Review F5: an invalid or colliding row is skipped without stopping the others, and pages follow a cursor, so
     * groups that keep failing never hold the batch.
     */
    public function testTheJobSkipsAnInvalidRowAndPagesWithACursor(): void {
        foreach (['a-gone', 'b-gone', 'c-gone'] as $gid) {
            $this->life->markPendingDelete($gid, $this->policy($gid), bin2hex(random_bytes(16)));
            $this->exists[$gid] = false;
        }
        // A row whose id does not match its digest (a collision) sorts somewhere among them.
        $this->database->dbal->insert('mcp_group_life', ['group_key' => IdentityKey::group('x-collision'), 'group_id' => 'X-Collision',
            'state' => 'pending_delete', 'gen' => bin2hex(random_bytes(16)), 'policy_rev' => '0']);
        $this->assertSame(3, $this->lifecycle->reconcile(), 'the invalid row fails alone');
        foreach (['a-gone', 'b-gone', 'c-gone'] as $gid) {
            $this->assertNull($this->life->get($gid));
        }

        // Pages of one: every run moves past the previous page, and wraps around at the end. The decidable group sorts
        // last by key, so without the cursor every run would stop at the failing ones before it.
        $failing = 'e';
        $reached = 'd';
        for ($i = 0; strcmp(IdentityKey::group($reached), max(IdentityKey::group($failing), IdentityKey::group('x-collision'))) < 0; ++$i) {
            $reached = 'd' . $i;
        }
        foreach ([$reached, $failing] as $gid) {
            $this->life->markPendingDelete($gid, $this->policy($gid), bin2hex(random_bytes(16)));
        }
        $this->exists = [$reached => false];
        $this->assertSame(0, $this->lifecycle->reconcile(1), 'the first page holds a failing row');
        $decided = 0;
        for ($run = 0; $run < 3; ++$run) {
            $decided += $this->lifecycle->reconcile(1);
        }
        $this->assertSame(1, $decided, 'the failing rows never hold the batch');
        $this->assertNull($this->life->get($reached));
        $this->assertSame('pending_delete', $this->life->get($failing)['state']);
    }

    /** @return list<array{name:string}> */
    private function tableColumns(string $table): array {
        return $this->database->dbal->fetchAllAssociative('PRAGMA table_info(' . $table . ')');
    }
}
