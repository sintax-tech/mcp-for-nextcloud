<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Listener;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Listener\UserCauseListener;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\ResourceScope\ScopeCleanup;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Group\Events\BeforeUserRemovedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The relevance filter and composition of contracts §3.3.2/§3.3.3 on the recheck table in SQLite: the listener and
 * the recheck mapper are the production classes; the processor is a double that records what it is asked. T5 (unit
 * part: duplicate pairs of User::delete, exception in each handler), T19/F5 (no query inside a caller's transaction).
 */
final class UserCauseListenerTest extends TestCase {
    /** Whether the user also has an individual Allow of eligibility. */
    private bool $allow = false;
    private SqliteDatabase $database;
    private IDBConnection $db;
    private RecheckMapper $rechecks;
    private OAuthStore $store;
    /** @var list<array{string, string, string}> after() calls */
    private array $afters = [];
    /** @var list<string> deferUndated() calls */
    private array $undated = [];
    /** @var list<string> events during which flush() was called */
    private array $flushes = [];
    private string $current = '';
    /** Resolved eligibility of alice without the given groups; null = unknown. */
    private \Closure $eligibility;
    private bool $member = true;
    private bool $trusted = true;
    private ?string $life = GroupLifeMapper::ACTIVE;
    /** The policy revision a Before reads as R_b. */
    private string $revision = '7';
    private bool $revisionFails = false;
    /** @var list<bool> whether a transaction was open at each read of the revision */
    private array $revisionReads = [];
    /** @var list<array{string, string}> ScopeCleanup::deleted() calls */
    private array $cleanups = [];
    private bool $cleanupFails = false;
    /** Whether the listener's connection says a caller's transaction is open (only in the tests that build it that way). */
    private bool $inTransaction = false;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version001100Date20261014000000(), new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
        // staff grants eligibility; team grants an ordinary operation only.
        foreach ([['staff', '_system', 'eligible'], ['team', 'notes', 'edit']] as [$gid, $module, $operation]) {
            $this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group($gid), 'group_id' => $gid,
                'module' => $module, 'operation' => $operation]);
        }
        $this->rechecks = new RecheckMapper($this->db);
        $this->store = new InMemoryOAuthStore();
        $this->eligibility = static fn (array $without): ?bool => !in_array('staff', $without, true);
    }

    private function listener(?IDBConnection $db = null, ?RecheckMapper $rechecks = null, ?CauseProcessor $causes = null): UserCauseListener {
        if ($causes === null) {
            $causes = $this->createMock(CauseProcessor::class);
            $causes->method('after')->willReturnCallback(function (string $uid, string $key, string $gen): void {
                $this->afters[] = [$uid, $key, $gen];
            });
            $causes->method('deferUndated')->willReturnCallback(function (string $uid): void {
                $this->undated[] = $uid;
            });
            $causes->method('flush')->willReturnCallback(function (): void {
                $this->flushes[] = $this->current;
            });
        }
        $grants = new GroupGrantsMapper($this->db);
        $life = $this->createMock(GroupLifeMapper::class);
        $life->method('get')->willReturnCallback(fn (string $gid): ?array => $this->life === null ? null
            : ['groupId' => $gid, 'state' => $this->life, 'gen' => str_repeat('a', 32), 'policyRev' => '1']);
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('trusted')->willReturnCallback(fn (): bool => $this->trusted);
        $resolver = $this->createMock(PolicyResolver::class);
        $resolver->method('begin')->willReturnCallback(function (string $uid, array $added = [], array $removed = [], bool $observe = true): PolicyOperationContext {
            $this->assertFalse($observe, 'a pre-event handler never runs a writer command');
            $eligible = ($this->eligibility)($removed);
            // Eligible through a group source (never staff itself, which the overlay may remove); an individual
            // Allow, when set, is the raw value on top of it.
            $raw = $this->allow ? ['eligible' => ['present' => true, 'value' => '1']] : [];
            $candidates = match ($eligible) {
                true => [['groupId' => 'other', 'grants' => ['_system' => ['eligible']], 'trusted' => true, 'state' => 'active', 'gen' => str_repeat('b', 32)]],
                null => [['groupId' => 'x', 'grants' => ['_system' => ['eligible']], 'trusted' => false, 'state' => 'active']],
                false => [],
            };
            return new PolicyOperationContext('0', $this->createMock(PolicyTransaction::class), $raw, $candidates, true, true, [], true, true,
                \OCA\Mcp\Access\ResourceScope\ScopeGuard::read($this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class), null));
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_760_000_000);
        $state = $this->createMock(PolicyStateMapper::class);
        $state->method('state')->willReturnCallback(function (): array {
            $this->revisionReads[] = $this->inTransaction;
            if ($this->revisionFails) {
                throw new \RuntimeException('state read failed');
            }
            return ['revision' => $this->revision, 'lock_seq' => '0', 'auth_epoch' => '0'];
        });
        $cleanup = $this->createMock(ScopeCleanup::class);
        $cleanup->method('deleted')->willReturnCallback(function (string $uid, string $revision): array {
            $this->cleanups[] = [$uid, $revision];
            if ($this->cleanupFails) {
                throw new PolicyUnavailable('writer down');
            }
            return [];
        });
        return new UserCauseListener($causes, $rechecks ?? $this->rechecks, $this->store, $grants, $life, $classifier, $resolver,
            $db ?? $this->db, $time, new NullLogger(), $state, $cleanup);
    }

    private function user(string $uid = 'alice'): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function group(string $gid = 'staff'): IGroup {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('inGroup')->willReturnCallback(fn (): bool => $this->member);
        return $group;
    }

    /** @return list<string> reason:group of alice's causes */
    private function causes(): array {
        return array_map(static fn (array $c): string => $c['reason'] . ':' . ($c['groupId'] ?? ''), $this->rechecks->causes('alice'));
    }

    /** User::delete of a member of an eligible group and of an ordinary one: Before(U), then per group Before, the
     * nested Group::removeUser pair, the outer After, and finally UserDeletedEvent. */
    private function deletion(UserCauseListener $listener, IUser $user): void {
        $listener->handle(new BeforeUserDeletedEvent($user));
        foreach ([$this->group('staff'), $this->group('team')] as $group) {
            $listener->handle(new BeforeUserRemovedEvent($group, $user));
            $listener->handle(new BeforeUserRemovedEvent($group, $user));
            $listener->handle(new UserRemovedEvent($group, $user));
            $listener->handle(new UserRemovedEvent($group, $user));
        }
        $listener->handle(new UserDeletedEvent($user));
    }

    public function testADeletionRecordsOneAccountCauseAndTheGroupPairsAreNoOps(): void {
        $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2'], 0);
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame(['account_delete:'], $this->causes());
        $gen = $this->rechecks->causes('alice')[0]['gen'];
        // The rest of User::delete: no group cause, one After for the account cause, with its gen.
        foreach ([$this->group('staff'), $this->group('team')] as $group) {
            foreach ([new BeforeUserRemovedEvent($group, $this->user()), new BeforeUserRemovedEvent($group, $this->user()),
                new UserRemovedEvent($group, $this->user()), new UserRemovedEvent($group, $this->user())] as $event) {
                $listener->handle($event);
            }
        }
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame(['account_delete:'], $this->causes());
        $this->assertSame([['alice', IdentityKey::accountCause(), $gen]], $this->afters);
        // A later deletion of the same id in the same process is a new native operation: a new gen.
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertNotSame($gen, $this->rechecks->causes('alice')[0]['gen']);
    }

    /** Review F2: a pre-event never flushes the deferred work (writer commands, mutex); the After events do. */
    public function testNoPreEventFlushesTheDeferredWork(): void {
        $listener = $this->listener();
        $user = $this->user();
        foreach ([new BeforeUserDeletedEvent($user), new BeforeUserRemovedEvent($this->group(), $this->user('bob')),
            new UserRemovedEvent($this->group(), $this->user('bob')), new UserDeletedEvent($user)] as $event) {
            $this->current = $event::class;
            $listener->handle($event);
        }
        $this->assertSame([UserRemovedEvent::class, UserDeletedEvent::class], array_values(array_unique($this->flushes)));
    }

    /** Review F1: the Before of a relevant deletion fails to store its cause; the After still revokes, without a date. */
    public function testAFailedAccountRecordIsReconciledWithoutADateByItsAfter(): void {
        $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2'], 0);
        $rechecks = $this->createMock(RecheckMapper::class);
        $rechecks->method('record')->willThrowException(new \RuntimeException('insert failed'));
        $listener = $this->listener(null, $rechecks);
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame([], $this->undated);
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame(['alice'], $this->undated);
        $this->assertContains('', $this->flushes, 'processed at once: the account is gone, so it is cut');
        $this->assertSame([], $this->afters);
    }

    /**
     * Review F3: a deletion mark silences the removals of U only while its account cause is pending; a deletion that
     * failed and was consumed since, or one that stored nothing, does not silence later removals in this process.
     */
    public function testAStaleDeletionMarkSilencesNothing(): void {
        $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2'], 0);
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        // The backend refused the deletion: no more events. While the account cause is pending, it covers removals.
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame(['account_delete:'], $this->causes());
        // Once a consent or the reconciler consumed it, a removal of the last eligible group records its own cause.
        $this->database->dbal->executeStatement('DELETE FROM mcp_auth_recheck');
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame(['group_removal:staff'], $this->causes());
        // An irrelevant deletion (nothing stored) silences nothing either.
        $this->database->dbal->executeStatement('DELETE FROM mcp_auth_recheck');
        $this->store->tokens = [];
        $this->eligibility = static fn (array $without): ?bool => false;
        $other = $this->listener();
        $other->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame([], $this->causes());
        $this->eligibility = static fn (array $without): ?bool => !in_array('staff', $without, true);
        $other->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame(['group_removal:staff'], $this->causes());
    }

    public function testAnIrrelevantAccountRecordsNothingAndItsDeletionEventCutsNothing(): void {
        $this->eligibility = static fn (): bool => false;
        $listener = $this->listener();
        $this->deletion($listener, $this->user());
        $this->assertSame([], $this->causes());
        $this->assertSame([], $this->afters);
    }

    public function testRemovalFromTheLastEligibleGroupRecordsOneCauseAndOnlyItsAfterConsumesIt(): void {
        $listener = $this->listener();
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame(['group_removal:staff'], $this->causes());
        $gen = $this->rechecks->causes('alice')[0]['gen'];
        // The duplicate Before of the same native operation reuses its gen.
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame($gen, $this->rechecks->causes('alice')[0]['gen']);
        $listener->handle(new UserRemovedEvent($this->group(), $this->user()));
        $listener->handle(new UserRemovedEvent($this->group(), $this->user()));
        $this->assertSame([['alice', IdentityKey::group('staff'), $gen]], $this->afters);
        // Once consumed (the double does not consume; delete the row as the writer would), a new removal gets a new gen.
        $this->database->dbal->executeStatement('DELETE FROM mcp_auth_recheck');
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertNotSame($gen, $this->rechecks->causes('alice')[0]['gen']);
    }

    public function testRemovalsThatKeepEligibilityOrCannotGrantRecordNothing(): void {
        $cases = [
            'eligible through another source' => function (): void { $this->eligibility = static fn (): bool => true; },
            'ordinary grants only' => null,
            'untrusted source' => function (): void { $this->trusted = false; },
            'policy needs confirmation' => function (): void { $this->life = GroupLifeMapper::NEEDS_CONFIRM; },
            'not a member (Before without After)' => function (): void { $this->member = false; },
        ];
        foreach ($cases as $label => $setup) {
            $this->eligibility = static fn (array $without): ?bool => !in_array('staff', $without, true);
            [$this->trusted, $this->life, $this->member] = [true, GroupLifeMapper::ACTIVE, true];
            $setup?->__invoke();
            $listener = $this->listener();
            $group = $this->group($setup === null ? 'team' : 'staff');
            $listener->handle(new BeforeUserRemovedEvent($group, $this->user()));
            $listener->handle(new UserRemovedEvent($group, $this->user()));
            $this->assertSame([], $this->causes(), $label);
            $this->assertSame([], $this->afters, $label);
        }
    }

    /**
     * RF2 of the re-reading of F2: an individual Allow no longer hides a removal from the only group U would inherit
     * eligibility from. The cause is recorded; its processing re-reads eligibility (no cut while the Allow exists), and
     * a reset of the Allow prepared before it is replayed by the revision that processing advances.
     */
    public function testARemovalMaskedByAnIndividualAllowStillRecordsTheCause(): void {
        $this->allow = true;
        $this->eligibility = static fn (array $without): ?bool => !in_array('staff', $without, true);
        $listener = $this->listener();
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame(['group_removal:staff'], $this->causes());
        // Another group keeps the inherited eligibility: nothing to re-check.
        $this->database->dbal->executeStatement('DELETE FROM mcp_auth_recheck');
        $this->eligibility = static fn (): bool => true;
        $listener = $this->listener();
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $this->assertSame([], $this->causes());
    }

    public function testAFailedRelevanceReadStillRecordsTheCause(): void {
        $this->eligibility = static fn (): never => throw new \RuntimeException('directory down');
        $listener = $this->listener();
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user()));
        $listener->handle(new BeforeUserDeletedEvent($this->user('bob')));
        $this->assertSame(['group_removal:staff'], $this->causes());
        $this->assertCount(1, $this->rechecks->causes('bob'));
    }

    /** T19/F5: inside a caller's transaction no handler runs a single query; the fact goes to memory. */
    public function testInsideACallersTransactionNoHandlerRunsAnyQuery(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturn(true);
        foreach (['getQueryBuilder', 'executeQuery', 'executeStatement', 'prepare', 'beginTransaction', 'commit', 'rollBack'] as $method) {
            $db->expects($this->never())->method($method);
        }
        $rechecks = $this->createMock(RecheckMapper::class);
        $rechecks->expects($this->never())->method($this->anything());
        $this->store = $this->createMock(OAuthStore::class);
        $this->store->expects($this->never())->method($this->anything());
        $listener = $this->listener($db, $rechecks);
        $this->deletion($listener, $this->user());
        $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user('bob')));
        $listener->handle(new UserRemovedEvent($this->group(), $this->user('bob')));
        $this->assertSame(['alice', 'bob'], array_values(array_unique($this->undated)), 'the queue keeps one item per user');
        $this->assertSame([], $this->afters);
    }

    /** T5: an exception in any handler never reaches the core. */
    public function testAnExceptionInEachHandlerIsContained(): void {
        $causes = $this->createMock(CauseProcessor::class);
        $causes->method('flush')->willThrowException(new \RuntimeException('flush down'));
        $causes->method('after')->willThrowException(new \RuntimeException('writer down'));
        $rechecks = $this->createMock(RecheckMapper::class);
        $rechecks->method('record')->willThrowException(new \RuntimeException('insert failed'));
        $rechecks->method('causes')->willThrowException(new \RuntimeException('read failed'));
        foreach ([$this->listener(null, $rechecks), $this->listener(null, null, $causes)] as $listener) {
            $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2'], 0);
            $this->deletion($listener, $this->user());
            $listener->handle(new BeforeUserRemovedEvent($this->group(), $this->user('bob')));
            $listener->handle(new UserRemovedEvent($this->group(), $this->user('bob')));
        }
        $this->addToAssertionCount(1);
    }

    // --- the resource scope of the account (contracts B.3, F3) -----------------------------------------------------

    /** A connection that reports a caller's transaction according to $this->inTransaction and answers nothing else. */
    private function transactional(): IDBConnection {
        $db = $this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);
        foreach (['getQueryBuilder', 'executeQuery', 'executeStatement', 'prepare', 'beginTransaction', 'commit', 'rollBack'] as $method) {
            $db->expects($this->never())->method($method);
        }
        return $db;
    }

    public function testADeletionOutsideATransactionRemovesTheScopeQualifiedByTheRevisionItsBeforeRead(): void {
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame([false], $this->revisionReads, 'R_b is read once, in autocommit');
        $this->assertSame([], $this->cleanups, 'a pre-event removes nothing and takes no mutex');
        $this->revision = '9'; // the policy moves while the deletion runs: only R_b qualifies the removal
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([['alice', '7']], $this->cleanups);
    }

    public function testTheRevisionIsReadWhateverTheCredentialsOrEligibilityOfTheAccount(): void {
        $this->eligibility = static fn (): bool => false; // an account the cause protocol finds irrelevant
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame([], $this->causes());
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([['alice', '7']], $this->cleanups, 'the scope is not tied to credentials');
    }

    public function testABeforeInsideATransactionReadsNothingAndItsAfterRemovesNothing(): void {
        $this->inTransaction = true;
        $listener = $this->listener($this->transactional());
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame([], $this->revisionReads, 'no SQL inside a caller\'s transaction');
        $this->inTransaction = false; // the After arrives once the transaction is over
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([], $this->cleanups, 'a deletion captured inside a transaction removes nothing: the scope is kept');
    }

    public function testAnAfterInsideATransactionRemovesNothingEvenWithTheRevisionOfItsBefore(): void {
        $listener = $this->listener($this->transactional());
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame([false], $this->revisionReads);
        $this->inTransaction = true;
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([], $this->cleanups, 'the deferred path never deletes');
        $this->assertSame([false], $this->revisionReads, 'and reads nothing');
    }

    public function testADeletionThatNeverGetsItsAfterRemovesNothingAndTheNextOneUsesItsOwnRevision(): void {
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user())); // failed or rolled back: no After
        $this->assertSame([], $this->cleanups);
        $this->revision = '12';
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([['alice', '12']], $this->cleanups, 'never the stale revision of the earlier attempt');
    }

    public function testARevisionIsUsedByExactlyOneAfter(): void {
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([['alice', '7']], $this->cleanups);
        $this->revision = '20';
        // A second After of the same id (a duplicate, or the account recreated and deleted by something that sent none).
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([['alice', '7']], $this->cleanups, 'the consumed revision is never reused');
    }

    public function testAnAfterWithoutAnyBeforeRemovesNothing(): void {
        $listener = $this->listener();
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([], $this->cleanups);
    }

    public function testABeforeThatCannotReadTheRevisionLeavesTheScopeToTheJobAndKeepsTheCauseProtocol(): void {
        $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2'], 0);
        $this->revisionFails = true;
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $this->assertSame(['account_delete:'], $this->causes());
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([], $this->cleanups);
        $this->assertCount(1, $this->afters, 'the account cause is still consumed');
    }

    public function testAFailingScopeCleanupNeverStopsTheCauseProtocolOrReachesTheCore(): void {
        $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2'], 0);
        $this->cleanupFails = true;
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user()));
        $listener->handle(new UserDeletedEvent($this->user()));
        $this->assertSame([['alice', '7']], $this->cleanups);
        $this->assertCount(1, $this->afters, 'the credentials are still cut');
    }

    public function testTheScopeRevisionOfOneAccountIsNeverUsedForAnother(): void {
        $listener = $this->listener();
        $listener->handle(new BeforeUserDeletedEvent($this->user('alice')));
        $this->revision = '8';
        $listener->handle(new BeforeUserDeletedEvent($this->user('bob')));
        $listener->handle(new UserDeletedEvent($this->user('bob')));
        $listener->handle(new UserDeletedEvent($this->user('alice')));
        $this->assertSame([['bob', '8'], ['alice', '7']], $this->cleanups);
    }
}
