<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Listener\GroupLifecycleListener;
use OCA\Mcp\OAuth\Revocation\CredentialGeneration;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Grants\Groups\GroupLifecycle;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCA\Mcp\Tests\Integration\Support\PostgresQueryLog;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\BeforeGroupCreatedEvent;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Ticket 07 on the real server (validation T6 and the group part of T19/F5): real group deletion and creation through
 * the core's group manager and Database backend, with the app's registered listener at its registered priority. Probe
 * listeners before or after it observe the state between core's steps, or play another app that fails; the deferred
 * work of §3.3.6 is proven with the caller's own transaction on the official connection and, in the PostgreSQL cell,
 * with the statement log around the app's real handlers and the core calls.
 *
 * Where a holder must be eligible only through the group, the individual flag is removed after the consent, outside
 * the writer, and the effect of a cause is read on the generations (the cut). Group eligibility over consent and
 * Bearer is proven by GroupConsumersTest (ticket 09).
 */
final class GroupLifecycleTest extends OAuthAccountTestCase {
    /** @var list<string> */
    private array $groups = [];
    private ?EventProbe $probe = null;

    protected function tearDown(): void {
        $this->probe?->detach();
        foreach ($this->groups as $gid) {
            foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
                $qb = $this->official()->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))->executeStatement();
            }
            Server::get(IGroupManager::class)->get($gid)?->delete();
        }
        parent::tearDown();
    }

    private function gid(): string {
        $gid = 'mcp-it-life-' . bin2hex(random_bytes(6));
        $this->groups[] = $gid;
        return $gid;
    }

    /** A Database group, with the test user as a member when $member. */
    private function group(bool $member = true, ?string $gid = null): IGroup {
        $gid ??= $this->gid();
        $group = Server::get(IGroupManager::class)->createGroup($gid);
        $this->assertNotNull($group);
        if ($member) {
            $group->addUser($this->user);
        }
        return $group;
    }

    /**
     * The failed deletion above ends the request in production, and the recreation is another request. In this single
     * process the group manager still holds the deleted group: NC 32 to 34 drop it from their cache on the post-delete
     * hook, which a throwing listener of the Deleted event never lets run (NC 35 drops it on the pre-delete hook), and
     * createGroup() returns a cached group without creating anything. The cache is emptied the way a new request
     * starts.
     */
    private function forgetTheDeletedGroupLikeTheNextRequestWould(): void {
        $manager = Server::get(IGroupManager::class);
        $clear = new \ReflectionMethod($manager, 'clearCaches');
        $clear->invoke($manager);
    }

    /**
     * Saves a group policy granting eligibility, as an administrator's save will (ticket 08): rows, a new gen, active,
     * and policy_rev = the revision this very commit produces.
     *
     * @return string the lifecycle's gen
     */
    private function save(string $gid): string {
        $db = $this->official();
        $gen = bin2hex(random_bytes(16));
        Server::get(PolicyTransaction::class)->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($db, $gid, $gen): bool {
            $current = $attempt->state();
            $qb = $db->getQueryBuilder();
            $qb->delete('mcp_group_grants')->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))->executeStatement();
            $qb = $db->getQueryBuilder();
            $qb->insert('mcp_group_grants')
                ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($gid)))
                ->setValue('group_id', $qb->createNamedParameter($gid))
                ->setValue('module', $qb->createNamedParameter('_system'))
                ->setValue('operation', $qb->createNamedParameter('eligible'))->executeStatement();
            (new GroupLifeMapper($db))->put($gid, GroupLifeMapper::ACTIVE, $gen, DecimalCounter::increment($current['revision']));
            return true;
        });
        return $gen;
    }

    /** Leaves the user eligible only through groups: the individual flag goes, outside the writer (test setup). */
    private function groupOnly(): void {
        Server::get(IConfig::class)->deleteUserValue($this->uid, 'mcp', 'eligible');
        Server::get(IUserConfig::class)->clearCache($this->uid, false);
    }

    private function probe(): EventProbe {
        return $this->probe ??= new EventProbe(Server::get(IEventDispatcher::class), $this->official());
    }

    /** @return array{groupId:string, state:string, gen:string, policyRev:string}|null as another session reads it */
    private function life(string $gid): ?array {
        return (new GroupLifeMapper($this->openIndependentConnection()))->get($gid);
    }

    /** @return array<string, list<string>> the group's stored grants, as another session reads them */
    private function grants(string $gid): array {
        return (new GroupGrantsMapper($this->openIndependentConnection()))->forGroups([$gid])[0]['grants'];
    }

    /** @return array{revision:string, lock_seq:string, auth_epoch:string} as another session reads it */
    private function state(): array {
        return (new PolicyStateMapper($this->openIndependentConnection()))->state();
    }

    private function generations(): array {
        return (new AuthUsersMapper($this->openIndependentConnection()))->generations($this->uid);
    }

    private function causes(): array {
        return (new RecheckMapper($this->openIndependentConnection()))->causes($this->uid);
    }

    private function live(array $tokens): bool {
        $row = Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash((string)$tokens['access_token']));
        return $row !== null && CredentialGeneration::live($row, $this->generations(), Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
    }

    /**
     * T6: Group::delete of a Database group granting eligibility, the holder eligible only through it. Between the
     * core's Before and its deletion (a probe right after the app's handler) the lifecycle is pending_delete, the
     * holder's cause is recorded, and neither the mutex nor the revision moved; after GroupDeletedEvent the rows and
     * the lifecycle are gone, the holder is cut once and the cause is consumed.
     */
    public function testARealDeletionMarksPendingThenPurgesAndCutsTheHolderWhoLostItsLastSource(): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group();
        $gid = $group->getGID();
        $this->save($gid);
        $this->groupOnly();
        $before = $this->state();
        $during = [];
        $this->probe()->on(BeforeGroupDeletedEvent::class, function () use ($gid, &$during): void {
            $during = ['life' => $this->life($gid)['state'] ?? null, 'state' => $this->state(), 'causes' => count($this->causes())];
        }, -1);
        foreach ([BeforeGroupDeletedEvent::class, GroupDeletedEvent::class] as $event) {
            $this->probe()->record($event);
        }

        $this->assertTrue($group->delete());

        $this->assertSame([BeforeGroupDeletedEvent::class, GroupDeletedEvent::class], $this->probe()->classes());
        $this->assertSame(['life' => GroupLifeMapper::PENDING_DELETE, 'state' => $before, 'causes' => 1], $during,
            'pending_delete and the cause, without the mutex or the revision');
        $this->assertNull($this->life($gid));
        $this->assertSame([], $this->grants($gid));
        $generations = $this->generations();
        $this->assertSame($generations['issue_seq'], $generations['revoked_below']);
        $this->assertSame([], $this->causes());
        $this->assertFalse($this->live($tokens));
        $this->assertSame(DecimalCounter::increment(DecimalCounter::increment($before['revision'])), $this->state()['revision'], 'purge and cut, one revision each');
    }

    /** T6 (other source): a holder still eligible individually gets no cause and keeps its credential. */
    public function testAHolderEligibleThroughAnotherSourceKeepsItsCredential(): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group();
        $this->save($group->getGID());
        $revoked = $this->generations()['revoked_below'];
        $this->assertTrue($group->delete());
        $this->assertNull($this->life($group->getGID()));
        $this->assertSame([], $this->causes());
        $this->assertSame($revoked, $this->generations()['revoked_below']);
        $this->assertSame($this->uid, $this->bearer($tokens));
    }

    /**
     * T6: another app throws before the app's GroupDeletedEvent handler, so the deletion's After never reaches it; the
     * group without holders stays pending_delete until the job, which finds it gone and purges it.
     */
    public function testALostDeletedEventLeavesPendingDeleteUntilTheJobPurges(): void {
        $group = $this->group(false);
        $gid = $group->getGID();
        $this->save($gid);
        $this->probe()->on(GroupDeletedEvent::class, static fn () => throw new RuntimeException('another app failed'), 10);
        try {
            $group->delete();
            $this->fail('The other listener did not throw');
        } catch (RuntimeException $e) {
            $this->assertSame('another app failed', $e->getMessage());
        }
        $this->probe?->detach();
        $this->assertSame(GroupLifeMapper::PENDING_DELETE, $this->life($gid)['state']);
        $this->assertGreaterThanOrEqual(1, Server::get(GroupLifecycle::class)->reconcile());
        $this->assertNull($this->life($gid));
        $this->assertSame([], $this->grants($gid));
    }

    /**
     * T6: a deletion that failed in its backend (Before without After, played by the listener's own Before) leaves
     * pending_delete; the job finds the group still there and suspends the policy: needs_confirm, rows kept, and the
     * member eligible only through it is unknown (503, no cut) until an administrator confirms.
     */
    public function testAFailedDeletionIsSuspendedByTheJobAndNeverGrantsAgainByItself(): void {
        $tokens = $this->exchange($this->consent());
        $group = $this->group();
        $gid = $group->getGID();
        $this->save($gid);
        $this->groupOnly();
        Server::get(GroupLifecycleListener::class)->handle(new BeforeGroupDeletedEvent($group));
        $this->assertSame(GroupLifeMapper::PENDING_DELETE, $this->life($gid)['state']);
        Server::get(GroupLifecycle::class)->reconcile();
        $this->assertSame(GroupLifeMapper::NEEDS_CONFIRM, $this->life($gid)['state']);
        $this->assertSame(['_system' => ['eligible']], $this->grants($gid));
        $context = Server::get(PolicyResolver::class)->begin($this->uid);
        try {
            $this->assertNull($context->eligible()->allowed, 'eligibility through a suspended group is unknown');
        } finally {
            $context->close();
        }
        $this->assertTrue($this->live($tokens), 'unknown is no proof of loss: nothing is cut by the job');
    }

    /**
     * T6: the same id deleted (its After lost) and recreated: the residual policy, saved before the creation began, is
     * purged by GroupCreatedEvent; a policy an administrator saves afterwards is never removed by the late
     * GroupDeletedEvent of the first group, which carries the old gen.
     */
    public function testARecreatedIdPurgesTheResidualPolicyAndALateDeletedEventSparesTheNewOne(): void {
        $group = $this->group(false);
        $gid = $group->getGID();
        $this->save($gid);
        $this->probe()->on(GroupDeletedEvent::class, static fn () => throw new RuntimeException('another app failed'), 10);
        try {
            $group->delete();
        } catch (RuntimeException) {
        }
        $this->probe?->detach();
        $this->assertSame(GroupLifeMapper::PENDING_DELETE, $this->life($gid)['state']);

        $this->forgetTheDeletedGroupLikeTheNextRequestWould();
        $recreated = $this->group(false, $gid);
        $this->assertNull($this->life($gid), 'policy_rev <= R_c: the residual policy is purged');
        $this->assertSame([], $this->grants($gid));

        $saved = $this->save($gid);
        Server::get(GroupLifecycleListener::class)->handle(new GroupDeletedEvent($recreated));
        $this->assertSame(['state' => GroupLifeMapper::ACTIVE, 'gen' => $saved], array_intersect_key($this->life($gid), ['state' => 1, 'gen' => 1]));
        $this->assertSame(['_system' => ['eligible']], $this->grants($gid));
    }

    /** T6: a policy saved between the creation's Before (R_c read) and GroupCreatedEvent is kept (policy_rev > R_c). */
    public function testAPolicySavedWhileTheGroupIsBeingCreatedIsKept(): void {
        $gid = $this->gid();
        $this->probe()->on(BeforeGroupCreatedEvent::class, function () use ($gid): void {
            $this->save($gid);
        }, -1);
        $this->group(false, $gid);
        $this->assertSame(GroupLifeMapper::ACTIVE, $this->life($gid)['state']);
        $this->assertSame(['_system' => ['eligible']], $this->grants($gid));
    }

    /** Creating G2 (and adding a member) while G1 is being deleted is never refused. */
    public function testCreatingAnotherGroupDuringADeletionIsNeverRefused(): void {
        $first = $this->group(false);
        $this->save($first->getGID());
        $second = $this->gid();
        $this->probe()->on(BeforeGroupDeletedEvent::class, function () use ($second): void {
            $created = Server::get(IGroupManager::class)->createGroup($second);
            $this->assertNotNull($created);
            $created->addUser($this->user);
        }, -1);
        $this->assertTrue($first->delete());
        $this->assertTrue(Server::get(IGroupManager::class)->get($second)->inGroup($this->user));
        $this->assertNull($this->life($first->getGID()));
    }

    public static function outcomes(): array {
        return ['commit' => [true], 'rollback' => [false]];
    }

    /**
     * T19/F5 (group part): the caller deletes a group with a policy inside its own transaction. The app's real
     * handlers run no query there (PostgreSQL: statement log around them, and no app table touched in the window of
     * the core call); the caller's next query and its commit or rollback work; at the first safe point the deferred
     * fact suspends the policy (needs_confirm), committed or not: nothing is deleted and no GroupDeletedEvent is
     * simulated.
     */
    #[DataProvider('outcomes')]
    public function testADeletionInsideACallersTransactionRunsNoAppQueryAndOnlySuspendsThePolicy(bool $commit): void {
        $group = $this->group(false);
        $gid = $group->getGID();
        $this->save($gid);
        $log = $this->cellDatabase() === 'pgsql' ? PostgresQueryLog::fromEnvironment() : null;
        if ($this->cellDatabase() === 'pgsql') {
            $this->assertNotNull($log, 'MCP_IT_PG_LOG is not set in the PostgreSQL cell');
        }
        $db = $this->official();
        $pid = $log?->backendPid($db);
        $listener = Server::get(GroupLifecycleListener::class);
        $before = $this->state();

        $db->beginTransaction();
        $handlersBegin = $log?->mark($db, 'group-handlers-begin');
        $listener->handle(new BeforeGroupDeletedEvent($group));
        $listener->handle(new GroupDeletedEvent($group));
        $listener->handle(new BeforeGroupCreatedEvent($gid));
        $listener->handle(new GroupCreatedEvent($group));
        $handlersEnd = $log?->mark($db, 'group-handlers-end');
        $this->assertTrue($group->delete());
        $coreEnd = $log?->mark($db, 'group-core-end');
        $db->executeQuery('SELECT 1 AS mcp_it_next_query')->closeCursor();
        $commit ? $db->commit() : $db->rollBack();
        $this->assertFalse($db->inTransaction());
        if ($log !== null) {
            $this->assertSame([], $log->statementsBetween($pid, $handlersBegin, $handlersEnd), 'an app handler ran a query inside the transaction');
            $core = $log->statementsBetween($pid, $handlersEnd, $coreEnd);
            $this->assertNotSame([], $core, 'the core deletion itself is captured');
            foreach ($core as $statement) {
                $this->assertStringNotContainsString('mcp_', $statement, 'an app table was touched inside the transaction');
                $this->assertStringStartsNotWith('ERROR:', $statement);
            }
        }
        $this->assertSame(GroupLifeMapper::ACTIVE, $this->life($gid)['state'], 'nothing changes before the safe point');
        $this->assertSame($before, $this->state());

        Server::get(CauseProcessor::class)->flush();
        $this->assertSame(GroupLifeMapper::NEEDS_CONFIRM, $this->life($gid)['state']);
        $this->assertSame(['_system' => ['eligible']], $this->grants($gid), 'nothing deleted, committed or not');
        $this->assertSame($commit, Server::get(IGroupManager::class)->get($gid) === null);
    }

    /**
     * T19, accepted limit (contracts §6.3 item 3 (i)): delete and recreate the same id in one transaction, committed.
     * Before the deferred processing another session still reads the old policy as active (the declared window); at
     * the next safe point it is suspended (needs_confirm), never purged.
     */
    public function testADeleteAndRecreateInOneTransactionIsSuspendedOnlyAtTheSafePoint(): void {
        $group = $this->group(false);
        $gid = $group->getGID();
        $this->save($gid);
        $db = $this->official();
        $db->beginTransaction();
        $this->assertTrue($group->delete());
        $this->assertNotNull(Server::get(IGroupManager::class)->createGroup($gid));
        $db->commit();
        $this->assertSame(GroupLifeMapper::ACTIVE, $this->life($gid)['state'], 'the accepted window: the old policy still reads as active');
        Server::get(CauseProcessor::class)->flush();
        $this->assertSame(GroupLifeMapper::NEEDS_CONFIRM, $this->life($gid)['state']);
        $this->assertSame(['_system' => ['eligible']], $this->grants($gid));
    }
}
