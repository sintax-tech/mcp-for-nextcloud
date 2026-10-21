<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OC\DB\Connection;
use OC\DB\ConnectionAdapter;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Listener\UserCauseListener;
use OCA\Mcp\OAuth\Revocation\CredentialGeneration;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCA\Mcp\Tests\Integration\Support\PostgresQueryLog;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\BeforeUserRemovedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Ticket 06 on the real server (validation T5, T7 (a)/(d)/(e), T8, T19 F5 and lock wait): real account deletion and
 * group removal through the core, with its own event order and duplicate pairs; the app's listener is the registered
 * one, at its registered priority. A failure of one of its handlers is injected by taking the app's recheck table away
 * only while core dispatches that event (probe listeners before and after it); another app's failure is a probe that
 * throws before it. The deferred work of §3.3.6 is proven with the caller's own transaction on the official
 * connection, and, in the PostgreSQL cell, with the statement log around the app's real handler.
 *
 * Where a test needs a user eligible only through a group, the individual flag is removed after the consent, outside
 * the writer, and the effect of a cause is read on the generations (the cut). Group eligibility over consent and
 * Bearer is proven by GroupConsumersTest (ticket 09).
 */
final class UserCausesTest extends OAuthAccountTestCase {
    /** @var list<string> */
    private array $groups = [];
    private ?EventProbe $probe = null;
    private bool $tableAway = false;

    protected function tearDown(): void {
        $this->probe?->detach();
        if ($this->tableAway) {
            $this->restoreTable();
        }
        foreach ($this->groups as $gid) {
            foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
                $qb = $this->official()->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))->executeStatement();
            }
            Server::get(IGroupManager::class)->get($gid)?->delete();
        }
        parent::tearDown();
    }

    /** A Database group holding the test user; with $eligible, an active policy granting eligibility. */
    private function group(bool $eligible): IGroup {
        $gid = 'mcp-it-cause-' . bin2hex(random_bytes(6));
        $this->groups[] = $gid;
        $group = Server::get(IGroupManager::class)->createGroup($gid);
        $this->assertNotNull($group);
        $group->addUser($this->user);
        if ($eligible) {
            $db = $this->official();
            $life = new GroupLifeMapper($db);
            Server::get(PolicyTransaction::class)->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($db, $life, $gid): bool {
            $state = $attempt->state();
                $qb = $db->getQueryBuilder();
                $qb->insert('mcp_group_grants')
                    ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($gid)))
                    ->setValue('group_id', $qb->createNamedParameter($gid))
                    ->setValue('module', $qb->createNamedParameter('_system'))
                    ->setValue('operation', $qb->createNamedParameter('eligible'))->executeStatement();
                $life->put($gid, GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16)), $state['revision']);
                return true;
            });
        }
        return $group;
    }

    /** Leaves the user eligible only through groups: the individual flag goes, outside the writer (test setup). */
    private function groupOnly(): void {
        Server::get(IConfig::class)->deleteUserValue($this->uid, 'mcp', 'eligible');
        Server::get(IUserConfig::class)->clearCache($this->uid, false);
    }

    private function probe(): EventProbe {
        return $this->probe ??= new EventProbe(Server::get(IEventDispatcher::class), $this->official());
    }

    /** The app's handler of $event fails on its storage; every other handler and core run as usual. */
    private function failAppHandlerDuring(string $event): void {
        $this->probe()->on($event, fn () => $this->takeTableAway(), 1);
        $this->probe()->on($event, fn () => $this->restoreTable(), -1);
    }

    private function takeTableAway(): void {
        $this->official()->executeStatement('ALTER TABLE *PREFIX*mcp_auth_recheck RENAME TO *PREFIX*mcp_auth_recheck_away');
        $this->tableAway = true;
    }

    private function restoreTable(): void {
        $this->official()->executeStatement('ALTER TABLE *PREFIX*mcp_auth_recheck_away RENAME TO *PREFIX*mcp_auth_recheck');
        $this->tableAway = false;
    }

    /** @return array{issue_seq:string, revoked_below:string} the user's generations as another session reads them */
    private function generations(): array {
        return (new AuthUsersMapper($this->openIndependentConnection()))->generations($this->uid);
    }

    /** @return list<string> reason:group of the user's pending causes, as another session reads them */
    private function causes(): array {
        return array_map(static fn (array $c): string => $c['reason'] . ':' . ($c['groupId'] ?? ''),
            (new RecheckMapper($this->openIndependentConnection()))->causes($this->uid));
    }

    /** Whether the grant of these tokens is still live by its generation and epoch (owner checks aside). */
    private function live(array $tokens): bool {
        $row = Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash((string)$tokens['access_token']));
        return $row !== null && CredentialGeneration::live($row, $this->generations(), Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
    }

    /** T5: User::delete of a member of an eligible group and of a plain one, with its duplicate pairs. */
    public function testARealDeletionCutsOnceAndItsAccountCauseIsConsumed(): void {
        $tokens = $this->exchange($this->consent());
        $this->group(true);
        $this->group(false);
        foreach ([BeforeUserDeletedEvent::class, BeforeUserRemovedEvent::class, UserRemovedEvent::class, UserDeletedEvent::class] as $event) {
            $this->probe()->record($event);
        }
        $state = Server::get(PolicyStateMapper::class);
        $revision = $state->state()['revision'];
        $issued = $this->generations()['issue_seq'];

        $this->assertTrue($this->user->delete());
        $this->user = null;

        $counts = array_count_values($this->probe()->classes());
        $this->assertSame([BeforeUserDeletedEvent::class => 1, BeforeUserRemovedEvent::class => 4, UserRemovedEvent::class => 4,
            UserDeletedEvent::class => 1], [BeforeUserDeletedEvent::class => $counts[BeforeUserDeletedEvent::class] ?? 0,
            BeforeUserRemovedEvent::class => $counts[BeforeUserRemovedEvent::class] ?? 0, UserRemovedEvent::class => $counts[UserRemovedEvent::class] ?? 0,
            UserDeletedEvent::class => $counts[UserDeletedEvent::class] ?? 0], 'User::delete wraps Group::removeUser: two pairs per group');
        $this->assertNull(Server::get(IUserManager::class)->get($this->uid));
        $this->assertSame(['issue_seq' => $issued, 'revoked_below' => $issued], $this->generations());
        $this->assertSame([], $this->causes());
        $this->assertSame(DecimalCounter::increment($revision), $state->state()['revision'], 'one cut, one policy write');
        $this->assertSame([], $this->stamps($this->openIndependentConnection(), 'mcp_oauth_tokens'));
        $this->assertNull($this->bearer($tokens));
    }

    public static function handlers(): array {
        return array_map(static fn (string $event): array => [$event],
            [BeforeUserDeletedEvent::class, BeforeUserRemovedEvent::class, UserRemovedEvent::class, UserDeletedEvent::class]);
    }

    /** T5/T8: a failure of any one of the app's handlers never stops the core; the credential still dies. */
    #[DataProvider('handlers')]
    public function testAFailureInEachHandlerLetsTheDeletionCompleteAndTheCredentialStillDies(string $event): void {
        $tokens = $this->exchange($this->consent());
        $this->group(true);
        $this->failAppHandlerDuring($event);
        $this->assertTrue($this->user->delete());
        $this->user = null;
        $this->probe?->detach();
        $this->assertFalse($this->tableAway);
        $this->assertNull(Server::get(IUserManager::class)->get($this->uid));
        // The reconciler finds whatever cause stayed pending (After lost: account gone, cut); otherwise the use observes it.
        Server::get(CauseProcessor::class)->reconcile();
        $this->assertNull($this->bearer($tokens));
        $generations = $this->generations();
        $this->assertSame($generations['issue_seq'], $generations['revoked_below']);
        $this->assertSame([], $this->causes());
    }

    /**
     * T7 (d)/(e), T8 (v): the After of a deletion is lost; the id is recreated and its new holder consents, which
     * consumes the pending account cause with its cut before issuing. The late After of the first account (the
     * listener's entry, with the gen its Before stored) then finds nothing: the new code and grant stay valid.
     */
    public function testARecreatedAccountKeepsTheConsentMadeAfterItsPendingCauseWasConsumed(): void {
        $old = $this->exchange($this->consent());
        $this->failAppHandlerDuring(UserDeletedEvent::class);
        $this->assertTrue($this->user->delete());
        $this->probe?->detach();
        $pending = (new RecheckMapper($this->official()))->causes($this->uid);
        $this->assertCount(1, $pending);
        $this->assertSame(RecheckMapper::REASON_ACCOUNT, $pending[0]['reason']);

        $this->user = Server::get(IUserManager::class)->createUser($this->uid, bin2hex(random_bytes(24))) ?: null;
        $this->assertNotNull($this->user);
        Server::get(\OCA\Mcp\Access\Grants\Commands\GrantCommands::class)->setEligible($this->uid, true);
        $fresh = $this->exchange($this->consent());
        $code = $this->consent();
        $this->assertSame([], $this->causes(), 'the consent consumed the pending cause');

        Server::get(CauseProcessor::class)->after($this->uid, IdentityKey::accountCause(), $pending[0]['gen']);
        Server::get(UserCauseListener::class)->handle(new UserDeletedEvent($this->user));
        $this->assertSame($this->uid, $this->bearer($fresh));
        $this->assertSame($this->uid, $this->bearer($this->exchange($code)), 'a code issued after the consumption is preserved');
        $this->assertNull($this->bearer($old));
    }

    /**
     * Review F1: the Before of a deletion cannot store its cause and the id is recreated before the old token is ever
     * used. The After reconciled the deletion without a date (account gone: cut), so the old token never authenticates
     * on the new account, while the new holder's own consent works.
     */
    public function testAFailedAccountCauseStillCutsSoARecreatedAccountNeverInheritsTheOldToken(): void {
        $old = $this->exchange($this->consent());
        $this->failAppHandlerDuring(BeforeUserDeletedEvent::class);
        $this->assertTrue($this->user->delete());
        $this->probe?->detach();
        $this->assertSame([], $this->causes(), 'the Before stored nothing');
        $this->user = Server::get(IUserManager::class)->createUser($this->uid, bin2hex(random_bytes(24))) ?: null;
        $this->assertNotNull($this->user);
        $writer = Server::get(PolicyTransaction::class);
        Server::get(\OCA\Mcp\Access\Grants\Commands\GrantCommands::class)->setEligible($this->uid, true);
        Server::get(\OCA\Mcp\Access\Grants\Commands\GrantCommands::class)->setConnected($this->uid, true);
        $this->assertNull($this->bearer($old));
        $this->assertSame($this->uid, $this->bearer($this->exchange($this->consent())));
    }

    /**
     * Review F5: the account deletion and the account disable inside a caller's transaction. The app's real handlers
     * of both deletion events, called between statement-log markers inside the transaction, run no query (PostgreSQL
     * cell); core's User::delete and setEnabled with their real dispatch touch no app table there; the caller commits.
     * The deletion is then reconciled without a date at the next safe point (account gone: cut). The disable inside a
     * transaction is left to the next use, as before this ticket (the account check refuses the credential).
     */
    public function testAnAccountDeletionAndDisableInsideACallersTransactionRunNoAppQuery(): void {
        $tokens = $this->exchange($this->consent());
        $log = $this->cellDatabase() === 'pgsql' ? PostgresQueryLog::fromEnvironment() : null;
        if ($this->cellDatabase() === 'pgsql') {
            $this->assertNotNull($log, 'MCP_IT_PG_LOG is not set in the PostgreSQL cell');
        }
        $db = $this->official();
        $pid = $log?->backendPid($db);
        $windows = [];
        $listener = Server::get(UserCauseListener::class);

        $db->beginTransaction();
        $begin = $log?->mark($db, 'disable-begin');
        $this->user->setEnabled(false);
        $windows['core disable'] = [$begin, $log?->mark($db, 'disable-end')];
        $db->rollBack();
        // The rollback restored the stored flag; the account object still holds the value it set in memory.
        $this->user->setEnabled(true);

        $before = $this->generations();
        $db->beginTransaction();
        $begin = $log?->mark($db, 'handlers-begin');
        $listener->handle(new BeforeUserDeletedEvent($this->user));
        $listener->handle(new UserDeletedEvent($this->user));
        $windows['app handlers'] = [$begin, $log?->mark($db, 'handlers-end')];
        $begin = $log?->mark($db, 'delete-begin');
        $this->assertTrue($this->user->delete());
        $windows['core delete'] = [$begin, $log?->mark($db, 'delete-end')];
        $db->executeQuery('SELECT 1 AS mcp_it_next_query')->closeCursor();
        $db->commit();
        $this->user = null;
        $this->assertFalse($db->inTransaction());
        if ($log !== null) {
            $this->assertSame([], $log->statementsBetween($pid, ...$windows['app handlers']), 'an app handler ran a query inside the transaction');
            foreach (['core disable', 'core delete'] as $window) {
                $statements = $log->statementsBetween($pid, ...$windows[$window]);
                $this->assertNotSame([], $statements, $window . ' is captured');
                foreach ($statements as $statement) {
                    $this->assertStringNotContainsString('mcp_', $statement, $window . ': an app table was touched inside the transaction');
                    $this->assertStringStartsNotWith('ERROR:', $statement, $window);
                }
            }
        }
        $this->assertSame([], $this->causes(), 'nothing was stored inside the transaction');
        $this->assertSame($before, $this->generations(), 'nothing changes before the safe point');
        Server::get(CauseProcessor::class)->flush();
        $after = $this->generations();
        $this->assertSame($after['issue_seq'], $after['revoked_below']);
        $this->assertNull($this->bearer($tokens));
    }

    /** T5: removal from the last eligible group, After delivered: cut in the same request, cause consumed. */
    public function testARemovalWithItsAfterCutsInTheSameRequest(): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group(true);
        $this->groupOnly();
        $group->removeUser($this->user);
        $generations = $this->generations();
        $this->assertSame($generations['issue_seq'], $generations['revoked_below']);
        $this->assertFalse($this->live($tokens));
        $this->assertSame([], $this->causes());
    }

    /**
     * Q1 (ii): another app throws after the backend removed the member, so the app's After never runs; the user is
     * added back. The next pass still revokes (ambiguous: eligible again, but only through that group).
     */
    public function testARemovalWithoutAfterIsRevokedByTheNextPassEvenAfterTheUserIsAddedBack(): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group(true);
        $this->groupOnly();
        $this->probe()->on(UserRemovedEvent::class, static fn () => throw new RuntimeException('another app failed'), 10);
        try {
            $group->removeUser($this->user);
            $this->fail('The other listener did not throw');
        } catch (RuntimeException $e) {
            $this->assertSame('another app failed', $e->getMessage());
        }
        $this->probe?->detach();
        $this->assertCount(1, $this->causes());
        $this->assertTrue($this->live($tokens), 'nothing is cut before the pass');
        $group->addUser($this->user);
        $this->assertGreaterThanOrEqual(1, Server::get(CauseProcessor::class)->reconcile());
        $this->assertFalse($this->live($tokens));
        $this->assertSame([], $this->causes());
    }

    /** Q1 (iii): eligible by an individual allow as well, the removal is irrelevant: nothing recorded, nothing cut. */
    public function testARemovalThatKeepsEligibilityThroughAnotherSourceTouchesNothing(): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group(true);
        $revoked = $this->generations()['revoked_below'];
        $group->removeUser($this->user);
        $this->assertSame([], $this->causes());
        $this->assertSame($revoked, $this->generations()['revoked_below']);
        $this->assertSame($this->uid, $this->bearer($tokens));
    }

    public static function outcomes(): array {
        return ['commit' => [true], 'rollback' => [false]];
    }

    /**
     * T19/F5: the caller removes a member inside its own transaction. The app's real handler runs no query there
     * (PostgreSQL: statement log around it, and no app table touched in the whole window of the core call), the
     * caller's next query and its commit or rollback work, and at the first safe point the deferred fact is reconciled
     * without a date: committed and no longer eligible, cut; rolled back and still eligible, nothing cut (§6.2.1).
     */
    #[DataProvider('outcomes')]
    public function testARemovalInsideACallersTransactionRunsNoAppQueryAndIsReconciledWithoutADate(bool $commit): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group(true);
        $this->groupOnly();
        $before = $this->generations();
        $log = $this->cellDatabase() === 'pgsql' ? PostgresQueryLog::fromEnvironment() : null;
        if ($this->cellDatabase() === 'pgsql') {
            $this->assertNotNull($log, 'MCP_IT_PG_LOG is not set in the PostgreSQL cell');
        }
        $db = $this->official();
        $pid = $log?->backendPid($db);
        $db->beginTransaction();
        $handlerBegin = $log?->mark($db, 'handler-begin');
        Server::get(UserCauseListener::class)->handle(new BeforeUserRemovedEvent($group, $this->user));
        $handlerEnd = $log?->mark($db, 'handler-end');
        $group->removeUser($this->user);
        $coreEnd = $log?->mark($db, 'core-end');
        $db->executeQuery('SELECT 1 AS mcp_it_next_query')->closeCursor();
        $commit ? $db->commit() : $db->rollBack();
        $this->assertFalse($db->inTransaction());
        if ($log !== null) {
            $this->assertSame([], $log->statementsBetween($pid, $handlerBegin, $handlerEnd), 'the app handler ran a query inside the transaction');
            $core = $log->statementsBetween($pid, $handlerEnd, $coreEnd);
            $this->assertNotSame([], $core, 'the core removal itself is captured');
            foreach ($core as $statement) {
                $this->assertStringNotContainsString('mcp_', $statement, 'an app table was touched inside the transaction');
                $this->assertStringStartsNotWith('ERROR:', $statement);
            }
        }
        $this->assertSame([], $this->causes(), 'nothing was stored inside the transaction');
        $this->assertSame($before, $this->generations(), 'nothing changes before the safe point');

        Server::get(CauseProcessor::class)->flush();
        $after = $this->generations();
        if ($commit) {
            $this->assertSame($after['issue_seq'], $after['revoked_below']);
            $this->assertFalse($this->live($tokens));
        } else {
            $this->assertTrue($group->inGroup($this->user) || in_array($group->getGID(), Server::get(IGroupManager::class)->getUserGroupIds($this->user), true));
            $this->assertSame($before, $after, 'an eligible user is never cut without a date, the old credential included');
            $this->assertTrue($this->live($tokens));
        }
    }

    /** Pending item of ticket 03: two sessions record the same cause, interleaved between the read and the insert. */
    public function testARecordRacingAnotherSessionBetweenItsReadAndInsertBecomesAnUpdate(): void {
        $other = new RecheckMapper($this->openIndependentConnection());
        $gid = 'mcp-it-race-' . bin2hex(random_bytes(4));
        $racing = bin2hex(random_bytes(16));
        $mine = bin2hex(random_bytes(16));
        $session = $this->openIndependentConnection(fn (Connection $inner): ConnectionAdapter => new class($inner, function () use ($other, $gid, $racing): void {
            $other->record($this->uid, RecheckMapper::REASON_GROUP, $gid, $racing, 1);
        }) extends ConnectionAdapter {
            private int $builders = 0;
            public function __construct(Connection $inner, private \Closure $beforeInsert) { parent::__construct($inner); }
            public function getQueryBuilder(): IQueryBuilder {
                // record(): the first builder reads the row, the second inserts it.
                if (++$this->builders === 2) {
                    ($this->beforeInsert)();
                }
                return parent::getQueryBuilder();
            }
        });
        (new RecheckMapper($session))->record($this->uid, RecheckMapper::REASON_GROUP, $gid, $mine, 2);
        $causes = (new RecheckMapper($this->openIndependentConnection()))->causes($this->uid);
        $this->assertCount(1, $causes);
        $this->assertSame($mine, $causes[0]['gen'], 'the later operation replaced the racing gen after verifying its row');
    }

    /**
     * T19 (pending item of ticket 04): a real lock wait on the issuance mutex. Another session holds the mutex; the
     * consent waits for the engine's lock timeout (shortened for this session), is rolled back and replayed at most
     * twice, then answers PolicyUnavailable with nothing written; once the mutex is free, the consent works.
     */
    public function testALockWaitOnTheIssuanceMutexRollsBackFullyAndIsRetriedAtMostTwice(): void {
        $db = $this->official();
        [$set, $reset] = match ($this->cellDatabase()) {
            'mariadb' => [['SET SESSION innodb_lock_wait_timeout = 1'], ['SET SESSION innodb_lock_wait_timeout = DEFAULT']],
            'pgsql' => [["SET lock_timeout = '1s'"], ['RESET lock_timeout']],
            default => [['PRAGMA busy_timeout = 200'], []],
        };
        $previousBusy = null;
        if ($this->cellDatabase() === 'sqlite') {
            $result = $db->executeQuery('PRAGMA busy_timeout');
            $previousBusy = (int)$result->fetchOne();
            $result->closeCursor();
            $reset = ['PRAGMA busy_timeout = ' . $previousBusy];
        }
        $holder = $this->openIndependentConnection();
        $holder->beginTransaction();
        (new PolicyStateMapper($holder))->acquire();
        $state = (new PolicyStateMapper($this->openIndependentConnection()))->state();
        $generations = $this->generations();
        foreach ($set as $statement) {
            $db->executeStatement($statement);
        }
        $started = microtime(true);
        try {
            Server::get(TokenService::class)->consent($this->request(), $this->uid);
            $this->fail('A consent went through a held mutex');
        } catch (PolicyUnavailable $e) {
            $this->assertNotNull($e->getPrevious());
        } finally {
            foreach ($reset as $statement) {
                $db->executeStatement($statement);
            }
            $holder->rollBack();
        }
        $elapsed = microtime(true) - $started;
        fwrite(STDERR, sprintf("T19 lock wait (%s): consent refused after %.2f s\n", $this->cellDatabase(), $elapsed));
        $this->assertLessThan(15.0, $elapsed, 'at most three attempts of one lock timeout each');
        // Three attempts of one lock timeout each (1 s; SQLite 0.2 s): the lock wait was replayed twice on every engine.
        $this->assertGreaterThan($this->cellDatabase() === 'sqlite' ? 0.5 : 2.5, $elapsed, 'the lock wait was not retried');
        $this->assertFalse($db->inTransaction());
        $this->assertSame($state, (new PolicyStateMapper($this->openIndependentConnection()))->state());
        $this->assertSame($generations, $this->generations());
        $this->assertSame([], $this->stamps($this->openIndependentConnection(), 'mcp_oauth_codes'));
        $this->assertSame($this->uid, $this->bearer($this->exchange($this->consent())));
    }
}
