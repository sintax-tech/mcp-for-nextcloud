<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy\Resolution;
use OCA\Mcp\Tests\Support\Access\Policy\GroupLifecycleDatabase;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F2 of the second reading of 1.1.0 (contracts §3.1, §4.1): removing an individual Allow of eligibility, by a reset to
 * inherit or by an undo that restores absence, cuts only when the user inherits no eligibility; an unknown inheritance
 * refuses the command without any write or cut; an explicit Deny and a disconnect still cut. Production writer and
 * mappers on SQLite; the inheritance is the closure GrantPolicy::inheritance() would be.
 */
final class InheritedEligibilityResetTest extends TestCase {
    use GroupLifecycleDatabase;

    private PolicyServices $commands;
    private AuthUsersMapper $authUsers;
    private string $staffGen = '';

    protected function setUp(): void {
        $this->lifecycleDatabase();
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $events = new PolicyEventsMapper($this->db, $time);
        $this->authUsers = new AuthUsersMapper($this->db);
        $this->commands = new PolicyServices(new PolicyTransaction($this->db, $this->state, $events, $this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class), new PolicyEventProcessor($events, new OAuthStore($this->db), new NullLogger(), $time)),
            $this->configData->mock($this), $this->createMock(IAppConfig::class), $users, $time, $this->state, $events, $this->authUsers, $this->rechecks, $this->grants, $this->life, new OAuthStore($this->db), new \OCA\Mcp\Db\ResourceScopeMapper($this->db));
        $this->staffGen = $this->policy('staff');
        // alice: individual Allow, connected, one credential (generation 2, the first issued), member of staff.
        $this->configData->user['alice']['mcp']['eligible'] = '1';
        $this->configData->user['alice']['mcp']['connected'] = '1';
        $this->commands->transaction->runInternal(['alice'], fn () => null, function (): bool {
            $this->authUsers->issue('alice');
            return false;
        });
    }

    /** @return \Closure(string): ?array the inheritance GrantPolicy::inheritance() reads, by case */
    private function inheritance(string $case): \Closure {
        return function (string $uid) use ($case): ?array {
            $this->assertFalse($this->db->inTransaction(), 'the inheritance is read in the external phase');
            return match ($case) {
                'group' => [['groupId' => 'staff', 'gen' => $this->life->get('staff')['gen']]],
                'none' => null,
                default => throw new GrantUnknown(),
            };
        };
    }

    private function inherit(string $case): array {
        return $this->commands->grants->applyCells($this->revision(), 'root', 'grants.commands',
            [['uid' => 'alice', 'key' => 'eligible', 'value' => null]], $this->inheritance($case));
    }

    private function cut(): string {
        return $this->authUsers->generations('alice')['revoked_below'];
    }

    public function testAResetToInheritWithAValidGroupKeepsTheCredentialsAndRenewsTheGroupsGen(): void {
        $this->inherit('group');
        $this->assertArrayNotHasKey('eligible', $this->configData->user['alice']['mcp']);
        $this->assertSame('0', $this->cut(), 'still eligible through staff: no cut');
        $row = $this->life->get('staff');
        $this->assertSame(GroupLifeMapper::ACTIVE, $row['state']);
        $this->assertNotSame($this->staffGen, $row['gen'], 'renewed, so a concurrent deletion Before judges alice again');
    }

    public function testAResetToInheritWithoutAnotherSourceCuts(): void {
        $this->inherit('none');
        $this->assertArrayNotHasKey('eligible', $this->configData->user['alice']['mcp']);
        $this->assertSame('2', $this->cut());
    }

    public function testAResetToInheritWithAnUnknownSourceIsRefusedWithoutAnyChange(): void {
        $revision = $this->revision();
        try {
            $this->inherit('unknown');
            $this->fail('an unknown inheritance must refuse');
        } catch (PolicyUnavailable $e) {
            $this->assertFalse($e->uncertain);
        }
        $this->assertSame('1', $this->configData->user['alice']['mcp']['eligible']);
        $this->assertSame('0', $this->cut());
        $this->assertSame($revision, $this->revision());
        $this->assertSame($this->staffGen, $this->life->get('staff')['gen']);
    }

    /** A group that went pending_delete between the phases: replayed, and the replay reads the new inheritance. */
    public function testAGroupLeavingActiveBetweenThePhasesIsNeverHeld(): void {
        $reads = 0;
        $this->commands->grants->applyCells($this->revision(), 'root', 'grants.commands',
            [['uid' => 'alice', 'key' => 'eligible', 'value' => null]], function () use (&$reads): ?array {
                if (++$reads === 1) {
                    $gen = $this->life->get('staff')['gen'];
                    $this->assertTrue($this->life->markPendingDelete('staff', $gen, bin2hex(random_bytes(16))));
                    return [['groupId' => 'staff', 'gen' => $gen]];
                }
                return null;
            });
        $this->assertSame(2, $reads);
        $this->assertSame('2', $this->cut(), 'the replay found nothing left to inherit');
        $this->assertSame(GroupLifeMapper::PENDING_DELETE, $this->life->get('staff')['state']);
    }

    /** The undo of a command that wrote an Allow over absence restores absence by the same rule. */
    public function testAnUndoRestoringAbsenceFollowsTheSameRule(): void {
        foreach (['group' => '0', 'none' => '2'] as $case => $cut) {
            $this->setUp();
            unset($this->configData->user['alice']['mcp']['eligible']);
            $saved = $this->commands->grants->applyCells($this->revision(), 'root', 'grants.commands',
                [['uid' => 'alice', 'key' => 'eligible', 'value' => '1']]);
            $this->commands->undo->restoreReceipt((string)$saved['receiptId'], $saved['revision'], 'root', $this->inheritance($case));
            $this->assertArrayNotHasKey('eligible', $this->configData->user['alice']['mcp']);
            $this->assertSame($cut, $this->cut(), $case);
        }
    }

    public function testAnExplicitDenyAndADisconnectStillCutWithAValidGroup(): void {
        $this->commands->grants->applyCells($this->revision(), 'root', 'grants.commands',
            [['uid' => 'alice', 'key' => 'eligible', 'value' => '0']], $this->inheritance('group'));
        $this->assertSame('2', $this->cut());
        $this->commands->transaction->runInternal(['alice'], fn () => null, function (): bool {
            $this->authUsers->issue('alice');
            return false;
        });
        $this->commands->grants->setConnected('alice', false);
        $this->assertSame('3', $this->cut());
    }
}
