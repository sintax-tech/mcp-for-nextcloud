<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Groups;
use OCA\Mcp\Tests\Support\Access\Policy\GroupLifecycleDatabase;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Listener\GroupLifecycleListener;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Grants\Groups\GroupGrantsService;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Grants\Groups\GroupSourceUntrusted;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use PHPUnit\Framework\TestCase;

/**
 * The group save of ticket 08 on SQLite, with the production writer and mappers (contracts §2.3, §3.3.5): preview and
 * save, the 422 of a group that cannot grant, the member hash, the lifecycle gen compared under the mutex, the holders
 * rule H0/H1 and "Confirm current policy". The external phase is a double that records what it was given and answers
 * the source predicate, the member list and who loses its last source; GroupGrantsServiceTest covers the real one.
 * Validation T5 (part F3), T6 (422 without write) and T16 (server part).
 */
final class GroupGrantCommandsTest extends TestCase {
    use GroupLifecycleDatabase;

    private bool $trusted = true;
    /** @var list<string> */
    private array $members = ['ana', 'bob'];
    /** @var list<string> holders the external phase decides lose their last source */
    private array $losing = [];
    /** @var list<\Closure(): void> run one per external phase, in order */
    private array $during = [];
    /** @var list<array> what each external phase was given */
    private array $admitted = [];
    /** @var list<\Closure(): void> run one per external phase of realAdmit(), after its decisions */
    private array $after = [];
    /** @var array<string, list<string>> uid => the groups it is in, for realAdmit() */
    private array $memberOf = [];
    private bool $injected = false;

    protected function setUp(): void {
        $this->lifecycleDatabase();
    }

    private function admit(): \Closure {
        return function (array $command): array {
            $this->assertFalse($this->db->inTransaction(), 'no directory read under the mutex');
            $this->admitted[] = $command;
            if ($this->during !== []) {
                (array_shift($this->during))();
            }
            return ['trusted' => $this->trusted, 'members' => $this->members,
                'cuts' => array_values(array_intersect($command['holders'], $this->losing))];
        };
    }

    /**
     * The production external phase (GroupGrantsService) over this database: each resolution is a real
     * PolicyOperationContext built from the stored grant rows and lifecycles of the holder's groups and its individual
     * eligibility flag; the source predicate is true and the members are $this->members.
     */
    private function realAdmit(): \Closure {
        $service = new GroupGrantsService($this->writer->groups, $this->classifier(), $this->resolver(), $this->grants, $this->life);
        return function (array $command) use ($service): array {
            $this->assertFalse($this->db->inTransaction(), 'no directory read under the mutex');
            $this->admitted[] = $command;
            $admission = $service->admit($command);
            if ($this->after !== []) {
                (array_shift($this->after))();
            }
            return $admission;
        };
    }

    /** Members of every group are $this->members; every group can grant. */
    private function classifier(): GroupSourceClassifier {
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('trusted')->willReturn(true);
        $classifier->method('members')->willReturnCallback(fn (): array => array_combine(array_map(static fn (string $uid): string => hash('sha256', $uid), $this->members), $this->members));
        return $classifier;
    }

    /** Resolutions as PolicyOperationContext over the stored rows and lifecycles of the user's groups ($this->memberOf). */
    private function resolver(): PolicyResolver {
        $resolver = $this->createMock(PolicyResolver::class);
        $resolver->method('begin')->willReturnCallback(function (string $uid, array $added, array $removed, bool $observe): PolicyOperationContext {
            $gids = array_values(array_diff($this->memberOf[$uid] ?? [], $removed));
            $lives = [];
            foreach ($this->life->forGroups($gids) as $row) {
                $lives[$row['groupId']] = $row;
            }
            $candidates = [];
            foreach ($this->grants->forGroups($gids) as $row) {
                if ($row['grants'] !== []) {
                    $candidates[] = $row + ['trusted' => true, 'state' => $lives[$row['groupId']]['state'] ?? null, 'gen' => $lives[$row['groupId']]['gen'] ?? null];
                }
            }
            $flag = $this->configData->user[$uid]['mcp']['eligible'] ?? null;
            $raw = $flag === null ? [] : ['eligible' => ['present' => true, 'value' => $flag]];
            return new PolicyOperationContext($this->revision(), $this->writer->transaction, $raw, $candidates, true, true, [], true, true, \OCA\Mcp\Access\ResourceScope\ScopeGuard::read($this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class), null));
        });
        return $resolver;
    }

    private function realSave(string $gid, array $grants): array {
        $preview = $this->writer->groups->previewGroupGrants($this->revision(), 'root', $gid, $grants, $this->realAdmit());
        return [$preview, fn (): array => $this->writer->groups->setGroupGrants($preview['revision'], 'root', $gid, $preview['grants'], $preview['previewId'], $this->realAdmit())];
    }

    /**
     * The production GroupLifecycleListener and CauseProcessor (with the real CauseClassifier over resolver()) on this
     * database, the lifecycle mapper being $life.
     *
     * @return array{GroupLifecycleListener, CauseProcessor}
     */
    private function listener(GroupLifeMapper $life): array {
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): \OCP\IUser {
            $user = $this->createMock(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $causes = new \OCA\Mcp\OAuth\Revocation\CauseProcessor($this->writer->credentials, $this->rechecks,
            new \OCA\Mcp\OAuth\Revocation\CauseClassifier($users, $this->resolver()), $this->createMock(\OCA\Mcp\OAuth\Revocation\DeferredQueue::class),
            $this->db, new \Psr\Log\NullLogger(), $this->apps->worker($this), $this->lifecycle);
        $listener = new \OCA\Mcp\Listener\GroupLifecycleListener($this->lifecycle, $causes, $life, $this->grants, $this->rechecks,
            $this->state, new OAuthStore($this->db), new AuthUsersMapper($this->db), $this->classifier(), $this->resolver(), $users,
            $this->db, $time, new \Psr\Log\NullLogger());
        return [$listener, $causes];
    }

    /** GrantPolicy::admission() over resolver(): the lifecycles of the groups that admit the user, or null. */
    private function admission(string $uid): ?array {
        $context = $this->resolver()->begin($uid, [], [], false);
        try {
            $eligibility = $context->eligible();
            return $eligibility->allowed === true ? $context->lifecycles($eligibility->groupIds) : null;
        } finally {
            $context->close();
        }
    }

    /**
     * An issuance a group admitted, as TokenService::asOwner() runs it: the admission in the external phase, then under
     * the mutex the admitting groups held (LifecycleHolds::hold()) and a credential of the next generation.
     *
     * @param (\Closure(): ?array)|null $admit the external phase; admission() by default
     * @return bool whether a credential was issued (false: the admission refused)
     */
    private function issueAdmitted(string $uid, ?\Closure $admit = null, ?PolicyServices $writer = null): bool {
        $issued = false;
        ($writer ?? $this->writer)->credentials->runCredential($uid, $admit ?? fn (): ?array => $this->admission($uid),
            function (?array $lifecycles, array $state, array $generations, PolicyAttempt $attempt) use ($uid, $writer, &$issued): bool {
                $issued = false;
                if ($lifecycles === null) {
                    return false;
                }
                ($writer ?? $this->writer)->holds->hold($attempt, $lifecycles);
                $this->token($uid, (new AuthUsersMapper($this->db))->issue($uid));
                $issued = true;
                return false;
            });
        return $issued;
    }

    /** Every account exists and is enabled. */
    private function users(): \OCP\IUserManager {
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): \OCP\IUser {
            $user = $this->createMock(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });
        return $users;
    }

    /** GrantPolicy::inheritance() over resolver(). */
    private function inheritance(string $uid): ?array {
        $context = $this->resolver()->begin($uid, [], [], false);
        try {
            $inherited = $context->inheritedEligibility();
            return $inherited->allowed === true ? $context->lifecycles($inherited->groupIds) : null;
        } finally {
            $context->close();
        }
    }

    /** Removes $uid from $gid as the core does: the production UserCauseListener sees Before and After. */
    private function removeMember(string $uid, string $gid, CauseProcessor $causes): void {
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $listener = new \OCA\Mcp\Listener\UserCauseListener($causes, $this->rechecks, new OAuthStore($this->db), $this->grants, $this->life, $this->classifier(), $this->resolver(), $this->db, $time, new \Psr\Log\NullLogger(), $this->createMock(\OCA\Mcp\Db\PolicyStateMapper::class), $this->createMock(\OCA\Mcp\Access\ResourceScope\ScopeCleanup::class));
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('inGroup')->willReturnCallback(fn (\OCP\IUser $user): bool => in_array($gid, $this->memberOf[$user->getUID()] ?? [], true));
        $user = $this->users()->get($uid);
        $listener->handle(new \OCP\Group\Events\BeforeUserRemovedEvent($group, $user));
        $this->memberOf[$uid] = array_values(array_diff($this->memberOf[$uid] ?? [], [$gid]));
        $listener->handle(new \OCP\Group\Events\UserRemovedEvent($group, $user));
    }

    /** The production writer over this database with another lifecycle mapper. */
    private function writerWith(GroupLifeMapper $life): PolicyServices {
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $events = new PolicyEventsMapper($this->db, $time);
        return new PolicyServices(new PolicyTransaction($this->db, $this->state, $events, $this->createMock(\OCP\Config\IUserConfig::class), $this->createMock(\OCP\IAppConfig::class), new \OCA\Mcp\Access\Policy\Events\PolicyEventProcessor($events, new OAuthStore($this->db), new \Psr\Log\NullLogger(), $time)),
            $this->configData->mock($this), $this->createMock(\OCP\IAppConfig::class), $this->users(), $time, $this->state, $events, new AuthUsersMapper($this->db), $this->rechecks, $this->grants, $life, new OAuthStore($this->db), new \OCA\Mcp\Db\ResourceScopeMapper($this->db));
    }

    private function preview(mixed $grants, string $gid = 'staff', string $actor = 'root'): array {
        return $this->writer->groups->previewGroupGrants($this->revision(), $actor, $gid, $grants, $this->admit());
    }

    private function saveFrom(array $preview, string $actor = 'root'): array {
        return $this->writer->groups->setGroupGrants($preview['revision'], $actor, $preview['groupId'], $preview['grants'],
            $preview['previewId'], $this->admit());
    }

    /** A credential holder: its generations and one live grant of the current epoch at its last generation. */
    private function holder(string $uid, string $issue = '2', string $revoked = '0'): void {
        $this->database->dbal->insert('mcp_auth_users', ['user_key' => IdentityKey::user($uid), 'user_id' => $uid,
            'issue_seq' => $issue, 'revoked_below' => $revoked]);
        $this->token($uid, $issue);
    }

    private function token(string $uid, string $gen): void {
        (new OAuthStore($this->db))->insertToken(['user_id' => $uid, 'client_id' => 'client', 'resource' => 'https://cloud/', 'scope' => 'mcp',
            'access_hash' => bin2hex(random_bytes(32)), 'access_expires' => $this->now + 3600, 'refresh_hash' => bin2hex(random_bytes(32)),
            'refresh_expires' => $this->now + 86400, 'created_at' => $this->now, 'auth_global_epoch' => '0', 'auth_gen' => $gen], $this->now);
    }

    /** An issuance under the mutex, as consent does: issue_seq + 1 and a credential of that generation. */
    private function issue(string $uid): void {
        $this->writer->transaction->runInternal([], fn () => null, function () use ($uid): bool {
            $this->token($uid, (new AuthUsersMapper($this->db))->issue($uid));
            return false;
        });
    }

    private function generations(string $uid): array {
        return (new AuthUsersMapper($this->db))->generations($uid);
    }

    /** @return list<array> payloads of the stored events of one kind, in order */
    private function stored(string $kind): array {
        return array_map(static fn (array $row): array => json_decode($row['payload'], true),
            $this->database->dbal->fetchAllAssociative('SELECT payload FROM mcp_policy_events WHERE kind = ? ORDER BY id', [$kind]));
    }

    public function testAPreviewChangesNothingAndItsSaveActivatesTheGroupAtThePostCommitRevision(): void {
        $before = $this->revision();
        $preview = $this->preview(['notes' => ['read', 'edit', 'edit'], '_system' => ['eligible']]);
        $this->assertSame($before, $this->revision(), 'a preview is no policy change');
        $this->assertSame(['_system' => ['eligible'], 'notes' => ['edit', 'read']], $preview['grants'], 'canonical set');
        $this->assertSame(['revision' => $before, 'groupId' => 'staff', 'current' => [], 'state' => null, 'members' => 2,
            'trusted' => true, 'withdraws' => false, 'revokes' => 0],
            array_diff_key($preview, ['previewId' => 1, 'grants' => 1]));
        $this->assertSame([], $this->rows('staff'));
        $this->assertCount(1, $this->stored(PolicyEventsMapper::KIND_PREVIEW));

        $result = $this->saveFrom($preview);

        $revision = DecimalCounter::increment($before);
        $this->assertSame(['revision' => $revision, 'changed' => true, 'state' => 'active', 'revoked' => 0], $result);
        $this->assertSame(['_system/eligible', 'notes/edit', 'notes/read'], $this->rows('staff'));
        $life = $this->life->get('staff');
        $this->assertSame('active', $life['state']);
        $this->assertSame($revision, $life['policyRev'], 'policy_rev is the revision of the commit, not the one before');
        $this->assertSame($revision, $this->revision());
        // Granting eligibility to the group writes no user preference: neither connected nor an override.
        $this->assertSame([], $this->configData->user);
        [$audit] = $this->stored(PolicyEventsMapper::KIND_AUDIT);
        $this->assertSame(['root', 'group_grants', $before, $revision], [$audit['actor'], $audit['action'], $audit['fromRevision'], $audit['revision']]);
        $this->assertSame([['gid' => 'staff', 'key' => 'group_grants', 'before' => null,
            'after' => '{"state":"active","grants":{"_system":["eligible"],"notes":["edit","read"]}}']], $audit['changes']);
    }

    /** T6: a group that cannot grant gets no preview and no save for a non-empty set; nothing is written. */
    public function testAGroupThatCannotGrantIsRefusedWithoutAnyWrite(): void {
        $revision = $this->revision();
        $this->trusted = false;
        try {
            $this->preview(['notes' => ['edit']]);
            $this->fail('An untrusted group was previewed');
        } catch (GroupSourceUntrusted $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertSame([], $this->stored(PolicyEventsMapper::KIND_PREVIEW));

        // The source changes between the preview and the save: the save reads the predicate again.
        $this->trusted = true;
        $preview = $this->preview(['notes' => ['edit']]);
        $this->trusted = false;
        try {
            $this->saveFrom($preview);
            $this->fail('An untrusted group was saved');
        } catch (GroupSourceUntrusted) {
        }
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame($revision, $this->revision());
        $this->assertSame([], $this->stored(PolicyEventsMapper::KIND_AUDIT));
    }

    /** Clarification 5: "Confirm current policy" is a save of the current set; with the predicate false it is a 422. */
    public function testConfirmingTheCurrentSetReactivatesASuspendedGroupWithANewGen(): void {
        $gen = $this->policy('staff', GroupLifeMapper::NEEDS_CONFIRM);
        $preview = $this->preview(['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->assertSame('needs_confirm', $preview['state']);
        $this->assertSame($preview['grants'], $preview['current']);

        $result = $this->saveFrom($preview);

        $this->assertSame(['changed' => true, 'state' => 'active', 'revoked' => 0], array_diff_key($result, ['revision' => 1]));
        $life = $this->life->get('staff');
        $this->assertSame('active', $life['state']);
        $this->assertNotSame($gen, $life['gen']);
        $this->assertSame($result['revision'], $life['policyRev']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'), 'same rows');

        $this->policy('team', GroupLifeMapper::NEEDS_CONFIRM);
        $revision = $this->revision();
        $this->trusted = false;
        try {
            $this->preview(['_system' => ['eligible'], 'notes' => ['edit']], 'team');
            $this->fail('An untrusted group was confirmed');
        } catch (GroupSourceUntrusted) {
        }
        $this->assertSame('needs_confirm', $this->life->get('team')['state']);
        $this->assertSame($revision, $this->revision());
    }

    /** Contracts §3.3.5: a save in pending_delete or needs_confirm substitutes the whole set of rows. */
    public function testASaveOfASuspendedGroupReplacesItsWholeSet(): void {
        $this->policy('staff', GroupLifeMapper::PENDING_DELETE, '0', [['notes', 'edit'], ['deck', 'read']]);
        $this->saveFrom($this->preview(['calendar' => ['read']]));
        $this->assertSame(['calendar/read'], $this->rows('staff'));
        $this->assertSame('active', $this->life->get('staff')['state']);
    }

    /** T16: a different member set between preview and save is a conflict, even with the same count. */
    public function testAChangedMemberSetOfTheSameCountIsAConflictWithNothingWritten(): void {
        $preview = $this->preview(['notes' => ['edit']]);
        $revision = $this->revision();
        $this->members = ['ana', 'carl'];
        try {
            $this->saveFrom($preview);
            $this->fail('A changed member set was saved');
        } catch (PolicyConflict) {
        }
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame($revision, $this->revision());
        $this->members = ['bob', 'ana'];
        $this->assertTrue($this->saveFrom($preview)['changed'], 'the same members in another order are the same set');
    }

    /**
     * Ticket 07 F3: pending_delete does not move the revision, so the save compares the lifecycle it read in the
     * external phase again under the mutex. A deletion's pre-event between the phases replays the attempt, which then
     * no longer matches its preview: 409, nothing written.
     */
    public function testAPendingDeleteBetweenThePhasesIsSeenUnderTheMutex(): void {
        $gen = $this->policy('staff');
        $preview = $this->preview(['_system' => ['eligible'], 'notes' => ['edit', 'read']]);
        $revision = $this->revision();
        $this->during = [function () use ($gen): void {
            $this->assertTrue($this->life->markPendingDelete('staff', $gen, bin2hex(random_bytes(16))));
        }];
        $this->admitted = [];
        try {
            $this->saveFrom($preview);
            $this->fail('A save ignored a pending deletion');
        } catch (PolicyConflict) {
        }
        $this->assertCount(2, $this->admitted, 'the transition invalidated the first attempt under the mutex');
        $this->assertSame('pending_delete', $this->life->get('staff')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame($revision, $this->revision());
    }

    /** Withdrawing eligibility cuts the holders the external phase found losing their last source, and only them. */
    public function testWithdrawingEligibilityCutsOnlyTheHoldersWhoLoseTheirLastSource(): void {
        $this->policy('staff');
        $this->holder('ana', '2');
        $this->holder('bob', '3');
        $this->holder('carl', '2', '2');
        $this->losing = ['ana', 'carl'];
        $preview = $this->preview(['notes' => ['edit']]);
        $this->assertTrue($preview['withdraws']);
        $this->assertSame(1, $preview['revokes']);
        $holders = $this->admitted[0]['holders'];
        sort($holders);
        $this->assertSame(['ana', 'bob'], $holders, 'every live holder not already cut, members or not');

        $result = $this->saveFrom($preview);

        $this->assertSame(1, $result['revoked']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'));
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '0'], $this->generations('bob'), 'another source keeps it');
        $this->assertSame(['notes/edit'], $this->rows('staff'));
        $this->assertSame([['v' => 1, 'scope' => 'user', 'uid' => 'ana', 'cut' => '2', 'epoch' => null]],
            $this->stored(PolicyEventsMapper::KIND_CLEANUP));
    }

    /** T5 F3: a member who consents between the external phase and the commit is a new holder in H1, and is cut. */
    public function testAConsentBetweenThePhasesReplaysTheWithdrawalAndCutsTheNewHolder(): void {
        $this->policy('staff');
        $this->holder('ana');
        $this->losing = ['ana', 'ursula'];
        $preview = $this->preview(['notes' => ['edit']]);
        $this->during = [fn () => $this->issue('ursula')];
        $this->admitted = [];

        $result = $this->saveFrom($preview);

        $this->assertCount(2, $this->admitted);
        $this->assertSame(['ana'], $this->admitted[0]['holders']);
        $this->assertContains('ursula', $this->admitted[1]['holders']);
        $this->assertSame(2, $result['revoked']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ursula'));
    }

    /** T5 F3 with connected already true: a holder's new consent only moves its issue_seq; H1 still differs. */
    public function testANewConsentOfAnExistingHolderBetweenThePhasesIsCutToo(): void {
        $this->policy('staff');
        $this->holder('ana', '2');
        $this->losing = ['ana'];
        $preview = $this->preview(['notes' => ['edit']]);
        $this->during = [fn () => $this->issue('ana')];
        $this->admitted = [];

        $this->saveFrom($preview);

        $this->assertCount(2, $this->admitted);
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '3'], $this->generations('ana'), 'the new credential is cut');
    }

    /** Contracts §2.3: holders that keep changing end, after two retries, in a 409 with nothing written. */
    public function testHoldersThatKeepChangingEndInAConflictAfterTwoRetries(): void {
        $this->policy('staff');
        $this->holder('ana');
        $this->losing = ['ana'];
        $preview = $this->preview(['notes' => ['edit']]);
        $revision = $this->revision();
        $this->during = [fn () => $this->issue('bob'), fn () => $this->issue('bob'), fn () => $this->issue('bob')];
        $this->admitted = [];
        try {
            $this->saveFrom($preview);
            $this->fail('A save went through changing holders');
        } catch (PolicyConflict) {
        }
        $this->assertCount(3, $this->admitted);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame('0', $this->generations('ana')['revoked_below']);
        $this->assertSame($revision, $this->revision(), 'the issuances move no revision; the save wrote nothing');
    }

    public function testSavingTheSameSetOfAnActiveGroupIsANoOp(): void {
        $gen = $this->policy('staff');
        $revision = $this->revision();
        $result = $this->saveFrom($this->preview(['_system' => ['eligible'], 'notes' => ['edit']]));
        $this->assertSame(['revision' => $revision, 'changed' => false, 'state' => 'active', 'revoked' => 0], $result);
        $this->assertSame($gen, $this->life->get('staff')['gen']);
        $this->assertSame([], $this->stored(PolicyEventsMapper::KIND_AUDIT));
    }

    /** Contracts §3.3.5 ("until the administrator confirms or clears"): clearing works on a group that cannot grant. */
    public function testClearingRemovesTheRowsAndTheLifecycleEvenOfAGroupThatCannotGrant(): void {
        $this->policy('staff', GroupLifeMapper::NEEDS_CONFIRM);
        $this->trusted = false;
        $this->members = [];
        $revision = $this->revision();
        $preview = $this->preview([]);
        $this->assertTrue($preview['withdraws']);

        $result = $this->saveFrom($preview);

        $this->assertSame(['revision' => DecimalCounter::increment($revision), 'changed' => true, 'state' => null, 'revoked' => 0], $result);
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertFalse($this->saveFrom($this->preview([]))['changed'], 'nothing left: a no-op');
    }

    public function testAPreviewIsTheCallersOwnForTwoMinutesAndForItsExactCommand(): void {
        $preview = $this->preview(['notes' => ['edit']]);
        try {
            $this->saveFrom($preview, 'eve');
            $this->fail('Another administrator saved the preview');
        } catch (ReceiptNotFound) {
        }
        foreach ([['staff', ['notes' => ['read']]], ['team', ['notes' => ['edit']]]] as [$gid, $grants]) {
            try {
                $this->writer->groups->setGroupGrants($preview['revision'], 'root', $gid, $grants, $preview['previewId'], $this->admit());
                $this->fail('A save differing from its preview was accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $this->writer->groups->setGroupGrants($preview['revision'], 'root', 'staff', $preview['grants'], bin2hex(random_bytes(16)), $this->admit());
            $this->fail('An invented preview was accepted');
        } catch (ReceiptNotFound) {
        }
        $this->now += PolicyLimits::PREVIEW_TTL;
        try {
            $this->saveFrom($preview);
            $this->fail('An expired preview was saved');
        } catch (ReceiptExpired) {
        }
        $this->now -= PolicyLimits::PREVIEW_TTL;
        // Any policy change after the preview makes it stale.
        $this->save('team');
        try {
            $this->saveFrom($preview);
            $this->fail('A stale preview was saved');
        } catch (PolicyConflict) {
        }
        $this->assertSame([], $this->rows('staff'));
    }

    public function testAnInvalidSetIsRefusedBeforeAnything(): void {
        $lock = $this->lockSeq();
        foreach ([['files' => ['nuke']], ['_system' => ['admin']], ['notes' => 'edit'], ['notes' => ['x' => 'edit']], 'notes', [['edit']]] as $grants) {
            try {
                $this->preview($grants);
                $this->fail('Invalid set accepted: ' . json_encode($grants));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame($lock, $this->lockSeq(), 'refused before the mutex');
        $this->assertSame([], PolicyEventPayload::groupGrants(['notes' => []]), 'a module without operations is no grant');
    }

    /**
     * Review F1: a holder kept by another group (G2) when G1 stops granting. G2's deletion pre-event between the phases
     * moves its lifecycle (no revision step); the save sees it under the mutex and replays, and with G2 suspended the
     * holder's eligibility without G1 is unknown: 503, nothing written, nobody cut. Once G2 is purged the withdrawal
     * cuts the holder, and granting eligibility again never brings the old credential back.
     */
    public function testASourceThatKeptAHolderAndMovesBeforeTheCommitIsSeenAndNeverLeavesTheHolderUncut(): void {
        $this->policy('staff');
        $teamGen = $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['staff', 'team'];
        $this->members = ['ana'];
        [$preview, $save] = $this->realSave('staff', []);
        $this->assertSame(0, $preview['revokes'], 'team keeps ana');
        $pending = bin2hex(random_bytes(16));
        $this->after = [fn () => $this->assertTrue($this->life->markPendingDelete('team', $teamGen, $pending))];
        $this->admitted = [];
        $revision = $this->revision();
        try {
            $save();
            $this->fail('A withdrawal decided on a source that moved');
        } catch (PolicyUnavailable $e) {
            $this->assertFalse($e->uncertain);
        }
        $this->assertCount(2, $this->admitted, 'team moved under the first attempt: replayed, then unknown');
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame('0', $this->generations('ana')['revoked_below']);
        $this->assertSame($revision, $this->revision());

        $this->assertTrue($this->lifecycle->deleted('team', $pending));
        [$preview, $save] = $this->realSave('staff', []);
        $this->assertSame(1, $preview['revokes']);
        $this->assertSame(1, $save()['revoked']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'));

        [, $save] = $this->realSave('staff', ['_system' => ['eligible']]);
        $this->assertTrue($save()['changed']);
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'the regrant leaves the old generation 2 cut');
    }

    /**
     * Review F1, the commit side: every active source the decision relied on gets a new gen, so a deletion's pre-event
     * that read it before the commit fails its conditional transition; its After then suspends the group (what
     * GroupLifecycleListener does without a recorded gen) instead of purging a source without a cause for its holders.
     */
    public function testASaveRenewsTheGenOfEverySourceItReliedOn(): void {
        $this->policy('staff');
        $teamGen = $this->policy('team');
        $this->holder('ana');
        $this->memberOf['ana'] = ['staff', 'team'];
        $this->members = ['ana'];
        [, $save] = $this->realSave('staff', []);
        $this->assertSame(0, $save()['revoked']);
        $team = $this->life->get('team');
        $this->assertSame('active', $team['state']);
        $this->assertNotSame($teamGen, $team['gen']);
        $this->assertFalse($this->life->markPendingDelete('team', $teamGen, bin2hex(random_bytes(16))));
        $this->assertTrue($this->lifecycle->needsConfirm('team'));
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('team'), 'suspended, never purged');
    }

    /**
     * Review F2: the lifecycle write is conditioned on the gen read under the mutex. A pre-event landing after that
     * read (injected right before the write, on the writer's own SQLite connection) makes it touch no row, and the
     * attempt is replayed instead of overwriting pending_delete. Here the injection is rolled back with the attempt, so
     * the replay saves; on two real connections the pre-event stays and the replay is a 409 (integration).
     */
    public function testALifecycleMovedAfterItsReadUnderTheMutexIsNeverOverwritten(): void {
        $this->policy('staff');
        $preview = $this->preview(['_system' => ['eligible'], 'notes' => ['edit', 'read']]);
        $this->during = [function (): void {
            // Armed at the mutex (the attempt's first statement), it fires at the next one: the lifecycle write.
            $this->database->beforeStatement = function (): void {
                $this->database->beforeStatement = function (): void {
                    $this->database->dbal->update('mcp_group_life', ['state' => 'pending_delete', 'gen' => bin2hex(random_bytes(16))],
                        ['group_key' => IdentityKey::group('staff')]);
                    $this->injected = true;
                };
            };
        }];
        $this->admitted = [];
        $result = $this->saveFrom($preview);
        $this->assertTrue($this->injected);
        $this->assertCount(2, $this->admitted, 'the conditional write refused the moved lifecycle');
        $this->assertTrue($result['changed']);
    }

    /**
     * Re-review R1, the probe of the review: while G2's deletion Before reads G2's lifecycle, a withdrawal of G1 commits,
     * keeps the holder through G2 and renews G2's gen, so the Before loses its conditional transition. It reads again,
     * marks, and only then judges the holders (mark first, F1 of the second reading): the holder now depends on G2
     * alone and gets its cause; the After purges G2 and cuts it. Granting G1 again never brings the old credential back.
     */
    public function testADeletionBeforeThatLosesItsTransitionToAWithdrawalJudgesTheHoldersAgain(): void {
        $this->policy('staff');
        $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['staff', 'team'];
        $this->members = ['ana'];
        [$preview, $save] = $this->realSave('staff', []);
        $this->assertSame(0, $preview['revokes'], 'team keeps ana');
        $life = new class($this->db, $save) extends GroupLifeMapper {
            public function __construct(\OCP\IDBConnection $db, private ?\Closure $between) { parent::__construct($db); }
            public function markSuspended(string $gid, string $expectedGen, string $gen): bool {
                if ($this->between !== null) {
                    // The withdrawal commits between the Before's judgement of the holders and its transition.
                    [$between, $this->between] = [$this->between, null];
                    \PHPUnit\Framework\Assert::assertSame(0, $between()['revoked']);
                }
                return parent::markSuspended($gid, $expectedGen, $gen);
            }
        };
        [$listener] = $this->listener($life);
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('team');

        $listener->handle(new \OCP\Group\Events\BeforeGroupDeletedEvent($group));
        $this->assertSame([], $this->rows('staff'), 'the withdrawal committed');
        $this->assertSame('pending_delete', $this->life->get('team')['state'], 'the second round marked it');
        $this->assertSame([IdentityKey::group('team')], array_column($this->rechecks->causes('ana'), 'causeKey'));
        $this->memberOf['ana'] = ['staff'];
        $listener->handle(new \OCP\Group\Events\GroupDeletedEvent($group));

        $this->assertNull($this->life->get('team'));
        $this->assertSame([], $this->rechecks->causes('ana'));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'), 'cut by its cause');
        [, $save] = $this->realSave('staff', ['_system' => ['eligible']]);
        $save();
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'the regrant leaves the old generation 2 cut');
    }

    /**
     * Re-review 2, R2, in the order of F1 of the second reading (mark first, then one capture): the deletion Before
     * marks pending_delete and only then records the holder's cause C. An issuance between the Before and the After
     * (runCredential with the real classifier) consumes C, cuts generation 2 and issues 3; nothing writes C back, so
     * the After finds nothing to do and generation 3 survives. No cause exists before the mark, so no issuance can
     * consume one while the group still admits: the one that consumes C runs after the mark, when the group admits
     * nobody (LifecycleHolds::hold() refuses it), so its credential never relied on the group.
     */
    public function testACauseAnIssuanceConsumedAfterTheMarkIsNeverWrittenBack(): void {
        $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        [$listener, $causes] = $this->listener(new GroupLifeMapper($this->db));
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('team');

        $listener->handle(new \OCP\Group\Events\BeforeGroupDeletedEvent($group));
        $this->assertSame('pending_delete', $this->life->get('team')['state']);
        $this->assertCount(1, $this->rechecks->causes('ana'), 'C is recorded after the mark');
        // The native deletion removed ana from team, so its classification is known (not eligible: cut).
        $this->memberOf['ana'] = [];
        $this->writer->credentials->runCredential('ana', fn () => null, function (): bool {
            $this->token('ana', (new AuthUsersMapper($this->db))->issue('ana'));
            return false;
        }, $causes->classifier());
        $this->assertSame([], $this->rechecks->causes('ana'), 'the issuance consumed C');
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '2'], $this->generations('ana'));
        $listener->handle(new \OCP\Group\Events\GroupDeletedEvent($group));

        $this->assertNull($this->life->get('team'));
        $this->assertSame([], $this->rechecks->causes('ana'), 'C is never written back');
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '2'], $this->generations('ana'), 'generation 3 survives');
    }

    /**
     * F1 of the second reading, first interleaving: G grants the only eligibility of ana, who holds no credential. The
     * deletion Before reads G's lifecycle; before its transition an issuance G admitted commits (consent, exchange or
     * refresh). The issuance renewed G's gen, so the transition loses; the Before reads again, marks, and only then
     * captures the holders: ana's new credential is there, gets its cause, and the After cuts it. Granting G again
     * never brings it back.
     */
    public function testAnIssuanceCommittedBeforeTheMarkIsCapturedAndCutByTheAfter(): void {
        $this->policy('team');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        $marks = 0;
        $life = new class($this->db, fn () => $this->assertTrue($this->issueAdmitted('ana')), $marks) extends GroupLifeMapper {
            public function __construct(\OCP\IDBConnection $db, private ?\Closure $between, private int &$marks) { parent::__construct($db); }
            public function markSuspended(string $gid, string $expectedGen, string $gen): bool {
                ++$this->marks;
                if ($this->between !== null) {
                    [$between, $this->between] = [$this->between, null];
                    $between();
                }
                return parent::markSuspended($gid, $expectedGen, $gen);
            }
        };
        [$listener] = $this->listener($life);
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('team');

        $listener->handle(new \OCP\Group\Events\BeforeGroupDeletedEvent($group));
        $this->assertSame(2, $marks, 'the issuance renewed the gen, so the first transition lost');
        $this->assertSame('pending_delete', $this->life->get('team')['state']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '0'], $this->generations('ana'));
        $this->assertSame([IdentityKey::group('team')], array_column($this->rechecks->causes('ana'), 'causeKey'));
        $this->memberOf['ana'] = [];
        $listener->handle(new \OCP\Group\Events\GroupDeletedEvent($group));

        $this->assertNull($this->life->get('team'));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'), 'cut by the After');
        $this->memberOf['ana'] = ['team'];
        [, $save] = $this->realSave('team', ['_system' => ['eligible']]);
        $save();
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'the regrant leaves generation 2 cut');
    }

    /**
     * F1, second interleaving: the issuance's local phase read G active, then the deletion's mark committed on another
     * connection before the issuance renewed G's gen. The conditional renewal matches nothing, the attempt rolls back
     * whole, and the replay's admission reads pending_delete and refuses: no credential, nothing for the After to miss.
     * (One SQLite connection: the concurrent mark is applied inside the attempt, where the renewal sees it, and again
     * outside it, as committed, before the replay.)
     */
    public function testAMarkCommittedBetweenTheReadAndTheRenewalRefusesTheIssuanceOnReplay(): void {
        $this->policy('team');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        $mark = bin2hex(random_bytes(16));
        $marked = false;
        $life = new class($this->db, function () use ($mark, &$marked): void {
            $this->database->dbal->update('mcp_group_life', ['state' => 'pending_delete', 'gen' => $mark], ['group_key' => IdentityKey::group('team')]);
            $marked = true;
        }) extends GroupLifeMapper {
            public function __construct(\OCP\IDBConnection $db, private ?\Closure $concurrent) { parent::__construct($db); }
            public function transition(string $gid, string $expectedGen, string $state, string $gen): bool {
                if ($this->concurrent !== null) {
                    [$concurrent, $this->concurrent] = [$this->concurrent, null];
                    $concurrent();
                }
                return parent::transition($gid, $expectedGen, $state, $gen);
            }
        };
        $attempts = 0;
        $issued = $this->issueAdmitted('ana', function () use (&$attempts, &$marked, $mark): ?array {
            if (++$attempts > 1 && $marked) {
                // The committed mark of the other connection, which the rollback of this one cannot undo.
                $this->database->dbal->update('mcp_group_life', ['state' => 'pending_delete', 'gen' => $mark], ['group_key' => IdentityKey::group('team')]);
            }
            return $this->admission('ana');
        }, $this->writerWith($life));

        $this->assertTrue($marked);
        $this->assertSame(2, $attempts, 'replayed once');
        $this->assertFalse($issued, 'the replay reads pending_delete and refuses');
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $this->generations('ana'));
        $this->assertSame([], (new OAuthStore($this->db))->liveHolders($this->now, '0'));
    }

    /** F1: an issuance whose external phase follows the mark refuses; G admits nobody while pending_delete. */
    public function testAnIssuanceAfterTheMarkIsRefused(): void {
        $this->policy('team');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        $this->assertTrue($this->life->markPendingDelete('team', $this->life->get('team')['gen'], bin2hex(random_bytes(16))));
        $this->assertFalse($this->issueAdmitted('ana'));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $this->generations('ana'));
    }

    /**
     * F1: two issuances G admitted, concurrent: the second commits during the first one's external phase and renews
     * G's gen. The first still finds G active under its mutex and the revision unchanged, so it renews again without
     * a replay; neither forces the other to replay.
     */
    public function testTwoConcurrentIssuancesOfOneGroupDoNotReplayEachOther(): void {
        $this->policy('team');
        $this->memberOf = ['ana' => ['team'], 'bob' => ['team']];
        $gen = $this->life->get('team')['gen'];
        $attempts = 0;
        $this->assertTrue($this->issueAdmitted('ana', function () use (&$attempts): ?array {
            ++$attempts;
            $admission = $this->admission('ana');
            if ($attempts === 1) {
                $this->assertTrue($this->issueAdmitted('bob'), 'bob commits in between');
            }
            return $admission;
        }));
        $this->assertSame(1, $attempts, 'no replay');
        $this->assertSame('2', $this->generations('ana')['issue_seq']);
        $this->assertSame('2', $this->generations('bob')['issue_seq']);
        $this->assertNotSame($gen, $this->life->get('team')['gen']);
        $this->assertSame('active', $this->life->get('team')['state']);
    }

    /**
     * RF2 of the re-reading of F2, the exact interleaving: ana has an individual Allow, a credential and team, the only
     * group she would inherit from. A reset of the Allow reads in its external phase that she inherits from team; then
     * her removal from team is delivered (Before and After) before the reset reaches its mutex. The removal records
     * its cause although the Allow masks it, and processing it (no cut: the Allow still exists) advances the revision,
     * so the reset meets a conflict and writes nothing. Sent again on the new revision it reads ana outside team and
     * cuts; adding her back never revives the old credential.
     */
    public function testAResetRacingTheRemovalOfTheMemberCutsInsteadOfKeepingAStaleInheritance(): void {
        $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        $this->configData->user['ana']['mcp'] = ['eligible' => '1', 'connected' => '1'];
        [, $causes] = $this->listener(new GroupLifeMapper($this->db));
        $writer = $this->writerWith(new GroupLifeMapper($this->db));
        $cell = [['uid' => 'ana', 'key' => 'eligible', 'value' => null]];

        try {
            $writer->grants->applyCells($this->revision(), 'root', 'grants.commands', $cell, function (string $uid) use ($causes): ?array {
                $inherited = $this->inheritance($uid);
                $this->assertNotNull($inherited, 'read while ana is in team');
                $this->removeMember('ana', 'team', $causes);
                return $inherited;
            });
            $this->fail('the processed removal moved the revision');
        } catch (\OCA\Mcp\Access\Policy\Transactions\PolicyConflict) {
        }
        $this->assertSame('1', $this->configData->user['ana']['mcp']['eligible'], 'nothing written');
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '0'], $this->generations('ana'), 'still eligible by the Allow: no cut');
        $this->assertSame([], $this->rechecks->causes('ana'), 'the cause was processed');

        $writer->grants->applyCells($this->revision(), 'root', 'grants.commands', $cell, fn (string $uid): ?array => $this->inheritance($uid));
        $this->assertArrayNotHasKey('eligible', $this->configData->user['ana']['mcp']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'), 'cut');
        $this->memberOf['ana'] = ['team'];
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'back in team, the old generation stays cut');
    }

    /** RF2, the undo that restores absence: the same race leaves the Allow in place (conflict), never a stale keep. */
    public function testAnUndoRacingTheRemovalOfTheMemberNeverKeepsAStaleInheritance(): void {
        $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        $this->configData->user['ana']['mcp'] = ['connected' => '1'];
        [, $causes] = $this->listener(new GroupLifeMapper($this->db));
        $writer = $this->writerWith(new GroupLifeMapper($this->db));
        $saved = $writer->grants->applyCells($this->revision(), 'root', 'grants.commands', [['uid' => 'ana', 'key' => 'eligible', 'value' => '1']]);

        try {
            $writer->undo->restoreReceipt((string)$saved['receiptId'], $saved['revision'], 'root', function (string $uid) use ($causes): ?array {
                $inherited = $this->inheritance($uid);
                $this->removeMember('ana', 'team', $causes);
                return $inherited;
            });
            $this->fail('the processed removal moved the revision');
        } catch (\OCA\Mcp\Access\Policy\Transactions\PolicyConflict) {
        }
        $this->assertSame('1', $this->configData->user['ana']['mcp']['eligible'], 'the Allow stays: ana is still eligible');
        $this->assertSame('0', $this->generations('ana')['revoked_below']);
        $this->memberOf['ana'] = [];
        $writer->grants->applyCells($this->revision(), 'root', 'grants.commands', [['uid' => 'ana', 'key' => 'eligible', 'value' => null]],
            fn (string $uid): ?array => $this->inheritance($uid));
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'removing the Allow now cuts');
    }

    /**
     * RF3 of the re-reading of F1: an issuance the group admitted renews its gen (active to active, revision unchanged)
     * between the preview and the save of an ordinary change; policy and members are the same, so the save succeeds.
     * A real transition in the same place (pending_delete) still refuses it.
     */
    public function testAnIssuanceRenewalBetweenPreviewAndSaveNeverInvalidatesThePreview(): void {
        $this->policy('team');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        [$preview, $save] = $this->realSave('team', ['_system' => ['eligible'], 'notes' => ['edit', 'read']]);
        $gen = $this->life->get('team')['gen'];
        $this->assertTrue($this->issueAdmitted('ana'), 'a refresh admitted by team');
        $this->assertNotSame($gen, $this->life->get('team')['gen']);
        $this->assertSame($preview['revision'], $this->revision(), 'the renewal moves no revision');
        $this->assertTrue($save()['changed']);
        $this->assertSame(['_system/eligible', 'notes/edit', 'notes/read'], $this->rows('team'));

        [$preview, $save] = $this->realSave('team', ['_system' => ['eligible']]);
        $this->assertTrue($this->life->markPendingDelete('team', $this->life->get('team')['gen'], bin2hex(random_bytes(16))));
        try {
            $save();
            $this->fail('a real transition invalidates the preview');
        } catch (PolicyConflict) {
        }
        $this->assertSame(['_system/eligible', 'notes/edit', 'notes/read'], $this->rows('team'));
    }
}
