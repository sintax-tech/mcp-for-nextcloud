<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Server;

/**
 * Ticket 09 on the real server (T9, T10, T11, T14): GrantPolicy, consent, issuance and Bearer validation resolve group
 * eligibility and grants through the container's services, over the core group manager and its Database backend.
 * Every observation of stored state is read from a second session.
 *
 * The test user has no individual eligibility here: the group is its only source.
 */
final class GroupConsumersTest extends OAuthAccountTestCase {
    private string $gid = '';
    private ?\stdClass $probe = null;

    protected function setUp(): void {
        parent::setUp();
        // Outside the writer, as an earlier version would have left it: the group becomes the only source.
        Server::get(IConfig::class)->deleteUserValue($this->uid, 'mcp', GrantPolicy::ELIGIBLE_KEY);
        $this->gid = 'mcp-it-consumers-' . bin2hex(random_bytes(6));
        $group = Server::get(IGroupManager::class)->createGroup($this->gid);
        $this->assertNotNull($group);
        $group->addUser($this->user);
    }

    protected function tearDown(): void {
        if ($this->probe !== null) {
            $this->probe->hook = null;
        }
        foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
            $qb = $this->official()->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid))))->executeStatement();
        }
        Server::get(IGroupManager::class)->get($this->gid)?->delete();
        parent::tearDown();
    }

    /**
     * Stores the group's grants and an active lifecycle through the writer, as a save of ticket 08 leaves them.
     *
     * @param array<string, list<string>> $grants
     * @return string the lifecycle gen
     */
    private function grant(array $grants, string $state = GroupLifeMapper::ACTIVE): string {
        $db = $this->official();
        $life = new GroupLifeMapper($db);
        $gen = bin2hex(random_bytes(16));
        Server::get(PolicyTransaction::class)->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($db, $life, $grants, $state, $gen): bool {
            $current = $attempt->state();
            $qb = $db->getQueryBuilder();
            $qb->delete('mcp_group_grants')->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid))))->executeStatement();
            foreach ($grants as $module => $operations) {
                foreach ($operations as $operation) {
                    $qb = $db->getQueryBuilder();
                    $qb->insert('mcp_group_grants')
                        ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid)))
                        ->setValue('group_id', $qb->createNamedParameter($this->gid))
                        ->setValue('module', $qb->createNamedParameter($module))
                        ->setValue('operation', $qb->createNamedParameter($operation))->executeStatement();
                }
            }
            $life->put($this->gid, $state, $gen, $current['revision']);
            return true;
        });
        return $gen;
    }

    /** @return array{issue_seq:string, revoked_below:string} the user's generations, as a second session reads them */
    private function generations(): array {
        return (new AuthUsersMapper($this->openIndependentConnection()))->generations($this->uid);
    }

    public function testGroupEligibilityAdmitsConsentExchangeAndBearer(): void {
        $this->grant(['_system' => ['eligible'], 'notes' => ['edit']]);
        $policy = Server::get(GrantPolicy::class);
        $tokens = $this->exchange($this->consent());
        $this->assertSame($this->uid, $this->bearer($tokens));
        $this->assertTrue($policy->canConnect($this->uid));
        $this->assertTrue($policy->granted($this->uid, 'notes', 'edit'));
        $this->assertFalse(Server::get(\OCP\Config\IUserConfig::class)->hasKey($this->uid, 'mcp', GrantPolicy::ELIGIBLE_KEY),
            'consent never writes an individual Allow');
    }

    /** Clarification 6: a sole indeterminate group answers unavailable over Bearer, and nothing is cut. */
    public function testASoleIndeterminateGroupIsUnavailableOverBearerWithoutACut(): void {
        $this->grant(['_system' => ['eligible']]);
        $tokens = $this->exchange($this->consent());
        $before = $this->generations();
        $this->grant(['_system' => ['eligible']], GroupLifeMapper::NEEDS_CONFIRM);
        try {
            $this->bearer($tokens);
            $this->fail('An unknown eligibility was answered');
        } catch (PolicyUnavailable) {
        }
        $this->assertSame($before, $this->generations());
        // The administrator confirms: the same credential works again, it was never cut.
        $this->grant(['_system' => ['eligible']]);
        $this->assertSame($this->uid, $this->bearer($tokens));
    }

    /** Losing the group's eligibility is a loss observed in use: the next Bearer use cuts, as an individual Deny. */
    public function testAGroupThatNoLongerGrantsIsAnObservedLoss(): void {
        $this->grant(['_system' => ['eligible']]);
        $tokens = $this->exchange($this->consent());
        $this->grant([]);
        $this->assertNull($this->bearer($tokens));
        $generations = $this->generations();
        $this->assertSame($generations['issue_seq'], $generations['revoked_below']);
    }

    /**
     * F3 of the review of ticket 07: a pending_delete between the external phase and the mutex moves no revision; the
     * consent compares the lifecycle gen under the mutex, replays, and the replay finds the group no longer grants.
     * The transition comes from another session, between the lifecycle read and the classification of the group.
     */
    public function testConsentComparesTheGrantingLifecycleUnderTheMutex(): void {
        $gen = $this->grant(['_system' => ['eligible']]);
        $revision = Server::get(PolicyStateMapper::class)->state()['revision'];
        $other = new GroupLifeMapper($this->openIndependentConnection());
        $gid = $this->gid;
        $this->probe = (object)['hook' => static function () use ($other, $gid, $gen): void {
            $other->markPendingDelete($gid, $gen, bin2hex(random_bytes(16)));
        }];
        Server::get(IGroupManager::class)->addBackend(new HookOnExistsGroupBackend($this->probe));
        try {
            Server::get(\OCA\Mcp\OAuth\TokenService::class)->consent($this->request(), $this->uid);
            $this->fail('A consent issued on a group being deleted');
        } catch (PolicyUnavailable) {
        }
        $this->assertNull($this->probe->hook, 'the transition landed between the phases');
        $second = $this->openIndependentConnection();
        $qb = $second->getQueryBuilder();
        $result = $qb->select('id')->from('mcp_oauth_codes')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->uid)))->executeQuery();
        $this->assertSame([], $result->fetchAll());
        $result->closeCursor();
        $this->assertSame('1', $this->generations()['issue_seq'], 'no generation was issued');
        $this->assertSame($revision, (new PolicyStateMapper($second))->state()['revision']);
        $this->assertSame(GroupLifeMapper::PENDING_DELETE, (new GroupLifeMapper($second))->get($this->gid)['state']);
    }

    /** T14: the container's GrantPolicy, reused across operations, reads each operation's own group policy. */
    public function testTheSameInstanceReadsEachOperationsGroupPolicy(): void {
        $policy = Server::get(GrantPolicy::class);
        $this->grant(['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->assertTrue($policy->granted($this->uid, 'notes', 'edit'));
        $this->grant(['_system' => ['eligible']]);
        $this->assertFalse($policy->granted($this->uid, 'notes', 'edit'));
        $reads = $policy->within($this->uid, function () use ($policy): array {
            $first = $policy->granted($this->uid, 'notes', 'edit');
            $this->grant(['_system' => ['eligible'], 'notes' => ['edit']]);
            return [$first, $policy->granted($this->uid, 'notes', 'edit')];
        });
        $this->assertSame([false, false], $reads, 'one operation reuses its snapshot');
        $this->assertTrue($policy->granted($this->uid, 'notes', 'edit'), 'the next operation reads the new one');
    }

    /** T10: the eligible filter and its total include a member eligible only through the group, before any page. */
    public function testTheEligibleFilterAndTotalsIncludeGroupMembers(): void {
        $policy = Server::get(GrantPolicy::class);
        $this->assertNotContains($this->uid, $policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY));
        $this->grant(['_system' => ['eligible']]);
        $this->assertContains($this->uid, $policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY));
        $page = Server::get(GrantMatrix::class)->page($this->uid, $this->gid, 1, 'eligible');
        $this->assertSame([$this->uid], array_column($page['users'], 'uid'));
        $this->assertSame(1, $page['total']);
        $this->assertSame(['source' => 'group', 'groupIds' => [$this->gid]], $page['users'][0]['origins']['eligible']);
        $policy->setEligible($this->uid, false);
        $this->assertNotContains($this->uid, $policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY), 'an individual Deny wins');
    }
}

/**
 * A backend that serves no group and runs a hook, once, the first time a group's existence is asked: the classification
 * read of a resolution, which follows its lifecycle read. Afterwards (hook null) it is inert for the rest of the process.
 */
final class HookOnExistsGroupBackend implements GroupInterface, INamedBackend {
    public function __construct(private \stdClass $state) {}
    public function getBackendName(): string {
        return 'Probe';
    }
    public function implementsActions($actions) {
        return false;
    }
    public function inGroup($uid, $gid) {
        return false;
    }
    public function getUserGroups($uid) {
        return [];
    }
    public function getGroups(string $search = '', int $limit = -1, int $offset = 0) {
        return [];
    }
    public function groupExists($gid) {
        [$hook, $this->state->hook] = [$this->state->hook, null];
        if ($hook !== null) {
            $hook();
        }
        return false;
    }
    public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
        return [];
    }
}
