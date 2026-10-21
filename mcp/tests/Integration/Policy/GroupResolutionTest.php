<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OC\Group\Database;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Resolution\LogsRoleReader;
use OCA\Mcp\Access\Grants\Groups\MembershipReader;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/** T6/T14: the real manager/backend and lifecycle storage, with repeated operations in one process. */
final class GroupResolutionTest extends IntegrationTestCase {
    private string $gid = '';
    private ?\stdClass $extraState = null;

    protected function tearDown(): void {
        if ($this->extraState !== null) {
            $this->extraState->serves = false;
        }
        parent::tearDown();
        if ($this->gid !== '') {
            foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
                $qb = $this->official()->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid))))->executeStatement();
            }
            Server::get(IGroupManager::class)->get($this->gid)?->delete();
        }
    }

    private function group(): void {
        $this->gid = 'mcp-it-resolution-' . bin2hex(random_bytes(8));
        $group = Server::get(IGroupManager::class)->createGroup($this->gid);
        $this->assertNotNull($group);
        $group->addUser(Server::get(IUserManager::class)->get(Fixtures::ALICE));
    }

    /** Lists the group as granting eligibility, active, through the writer like an administrator would. */
    private function grantEligibility(): GroupLifeMapper {
        $db = $this->official();
        $life = new GroupLifeMapper($db);
        Server::get(PolicyTransaction::class)->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($db, $life): bool {
            $state = $attempt->state();
            $qb = $db->getQueryBuilder();
            $qb->insert('mcp_group_grants')
                ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid)))
                ->setValue('group_id', $qb->createNamedParameter($this->gid))
                ->setValue('module', $qb->createNamedParameter('_system'))
                ->setValue('operation', $qb->createNamedParameter('eligible'))->executeStatement();
            $life->put($this->gid, 'active', bin2hex(random_bytes(16)), $state['revision']);
            return true;
        });
        return $life;
    }

    public function testActiveDatabaseGroupGrantsEligibilityThroughTheRealManager(): void {
        $this->group();
        $this->grantEligibility();
        $context = Server::get(PolicyResolver::class)->begin(Fixtures::ALICE);
        try {
            $eligible = $context->eligible();
            $this->assertTrue($eligible->allowed);
            $this->assertSame('group', $eligible->source);
            $this->assertSame([$this->gid], $eligible->groupIds);
        } finally { $context->close(); }
    }

    /**
     * M1 and the pending item of ticket 05 for 09: LogsAccess now reads the role through the fresh reader, so both are
     * compared with the 1.0.8 gate (IGroupManager::isAdmin/isInGroup over the listed groups) on the real manager.
     */
    public function testLogsRoleReaderMatchesLegacyGateOnTheRealManager(): void {
        $manager = Server::get(IGroupManager::class);
        $users = Server::get(IUserManager::class);
        $legacy = Server::get(LogsAccess::class);
        $reader = Server::get(LogsRoleReader::class);
        $membership = new MembershipReader($manager);
        $uids = [Fixtures::ALICE, Fixtures::BOB];
        foreach ($manager->get('admin')?->getUsers() ?? [] as $admin) {
            $uids[] = $admin->getUID();
            break;
        }
        $this->assertGreaterThan(2, count($uids), 'the instance needs an administrator to compare');
        foreach ($uids as $uid) {
            $user = $users->get($uid);
            $this->assertNotNull($user);
            $before = $legacy->available() && ($manager->isAdmin($uid)
                || array_filter($legacy->groups(), static fn (string $gid): bool => $manager->isInGroup($uid, $gid)) !== []);
            $this->assertSame(['available' => $legacy->available(), 'permitted' => $before],
                $reader->read($uid, $membership->read($user)), $uid);
            $this->assertSame($before, $legacy->permits($uid), $uid);
        }
    }

    public function testFreshMembershipBypassesAPreloadedManagerAcrossOperations(): void {
        $this->group();
        $manager = Server::get(IGroupManager::class);
        $alice = Server::get(IUserManager::class)->get(Fixtures::ALICE);
        $this->assertContains($this->gid, $manager->getUserGroupIds($alice));
        $reader = new MembershipReader($manager);
        $this->assertContains($this->gid, $reader->read($alice));
        // An independent backend object changes storage without resetting the preloaded manager.
        $backend = new Database($this->official());
        $this->assertTrue($backend->removeFromGroup(Fixtures::ALICE, $this->gid));
        $this->assertNotContains($this->gid, $reader->read($alice));
        $this->assertTrue($backend->addToGroup(Fixtures::ALICE, $this->gid));
        $this->assertContains($this->gid, $reader->read($alice));
        $this->assertNotContains($this->gid, $reader->read($alice, true, [], [$this->gid]));
    }

    public function testDuplicateDatabaseSourcePersistsNeedsConfirmAndNeverReactivatesByItself(): void {
        $this->group();
        $manager = Server::get(IGroupManager::class);
        $manager->get($this->gid); // Cached IGroup must not be used for classification.
        $classifier = new GroupSourceClassifier($manager);
        $this->assertTrue($classifier->trusted($this->gid));
        $life = $this->grantEligibility();
        // removeBackend is new in NC35. Keep this test backend registered but inert after the test,
        // using only APIs available across NC32–35; never clear the real manager's backends or caches.
        // A real object, not a PHPUnit double: a double answers null once its test has ended, and every later
        // test of this process reading memberships through the manager would see an invalid backend.
        $state = $this->extraState = (object)['serves' => true, 'gid' => $this->gid];
        $extra = new InertWhenDoneGroupBackend($state);
        $manager->addBackend($extra);
        $this->assertFalse($classifier->trusted($this->gid));
        $resolver = Server::get(PolicyResolver::class);
        $context = $resolver->begin(Fixtures::ALICE);
        try {
            $this->assertNull($context->eligible()->allowed);
            $this->assertSame('needs_confirm', $life->get($this->gid)['state']);
        } finally { $context->close(); }
        $state->serves = false;
        $this->assertTrue($classifier->trusted($this->gid));
        $context = $resolver->begin(Fixtures::ALICE);
        try { $this->assertNull($context->eligible()->allowed); }
        finally { $context->close(); }
    }

    public function testPositiveDatabaseExistenceCacheIsADeclaredLongProcessLimit(): void {
        $this->group();
        $cached = new Database($this->official());
        $this->assertTrue($cached->groupExists($this->gid));
        $external = new Database($this->openIndependentConnection());
        $this->assertTrue($external->deleteGroup($this->gid));
        $this->assertTrue($cached->groupExists($this->gid));
        $this->assertFalse((new Database($this->official()))->groupExists($this->gid));
    }
}

/** A second backend named Database while $state->serves; afterwards it answers like an empty backend. */
final class InertWhenDoneGroupBackend implements GroupInterface, INamedBackend {
    public function __construct(private \stdClass $state) {}
    public function getBackendName(): string {
        return 'Database';
    }
    public function implementsActions($actions) {
        return false;
    }
    public function inGroup($uid, $gid) {
        return $this->state->serves && $uid === Fixtures::ALICE && $gid === $this->state->gid;
    }
    public function getUserGroups($uid) {
        return $this->state->serves && $uid === Fixtures::ALICE ? [$this->state->gid] : [];
    }
    public function getGroups(string $search = '', int $limit = -1, int $offset = 0) {
        return [];
    }
    public function groupExists($gid) {
        return $this->state->serves && $gid === $this->state->gid;
    }
    public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
        return [];
    }
}
