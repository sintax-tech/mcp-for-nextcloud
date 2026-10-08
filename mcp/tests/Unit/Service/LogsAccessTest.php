<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Service\LogsGroupsConflict;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\InMemoryLocks;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The role gate of the logs module: only a Nextcloud administrator or a member of a group the administrator listed
 * may read the server log, and nobody may while the log is not a file the core can read back.
 */
final class LogsAccessTest extends TestCase {
    private InMemoryConfig $store;
    private InMemoryAppConfig $appConfig;
    private InMemoryLocks $locks;
    /** @var array<string, list<string>> members by group id */
    private array $groups = ['admin' => ['root'], 'ti' => ['tina'], 'rh' => ['rita'], '1' => ['uno'], '01' => ['zero-uno']];

    protected function setUp(): void {
        $this->store = new InMemoryConfig();
        $this->appConfig = new InMemoryAppConfig();
        $this->locks = new InMemoryLocks();
    }

    /** One request: its own app config cache over the shared store, and the shared locks. */
    private function access(): LogsAccess {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->groups['admin'], true));
        $groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($uid, $this->groups[$gid] ?? [], true));
        $groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => isset($this->groups[$gid]) ? $this->createMock(IGroup::class) : null);
        return new LogsAccess($this->store->mock($this), $groups, $this->appConfig->worker($this), $this->locks);
    }

    public function testOnlyAdministratorsPassWhileNoGroupIsListed(): void {
        $access = $this->access();
        $this->assertSame([], $access->groups());
        $this->assertTrue($access->permits('root'));
        $this->assertFalse($access->permits('tina'));
        $this->assertFalse($access->permits('rita'));
        $this->assertFalse($access->permits('nobody'));
    }

    public function testMembersOfAListedGroupPassAndOthersDoNot(): void {
        $access = $this->access();
        $access->setGroups(['ti']);
        $this->assertSame(['ti'], $this->access()->groups());
        $this->assertTrue($access->permits('tina'));
        $this->assertFalse($access->permits('rita'));
        $this->assertTrue($access->permits('root'));
    }

    public function testTheGroupsAreStoredUnderTheirOwnKeyAndAnEmptyListRemovesIt(): void {
        $access = $this->access();
        $access->setGroups(['ti', 'rh', 'ti']);
        $this->assertSame(['rh', 'ti'], json_decode($this->appConfig->stored['mcp'][LogsAccess::GROUPS_KEY], true));
        $access->setGroups([]);
        $this->assertArrayNotHasKey(LogsAccess::GROUPS_KEY, $this->appConfig->stored['mcp'] ?? []);
        $this->assertFalse($access->permits('tina'));
    }

    public function testAnUnknownGroupIsRefusedAndNothingIsWritten(): void {
        $access = $this->access();
        $access->setGroups(['ti']);
        try {
            $access->setGroups(['ti', 'ghost']);
            $this->fail('an unknown group must be refused');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(['ti'], $access->groups());
    }

    public function testAGroupDeletedLaterSimplyStopsMatching(): void {
        $access = $this->access();
        $access->setGroups(['ti']);
        unset($this->groups['ti']);
        $this->assertFalse($access->permits('tina'));
    }

    /**
     * A group deleted after it was listed must not lock the list: it shows up as missing, it may stay or be removed,
     * and only a group that was not listed before has to exist.
     */
    public function testAGroupDeletedLaterCanBeKeptOrRemovedButNotAdded(): void {
        $access = $this->access();
        $access->setGroups(['ti', 'rh']);
        unset($this->groups['ti']);
        $this->assertSame(['ti'], $access->missingGroups());

        $access->setGroups(['ti', 'rh'], ['rh', 'ti']);
        $this->assertSame(['rh', 'ti'], $access->groups());
        $access->setGroups(['rh'], ['rh', 'ti']);
        $this->assertSame(['rh'], $access->groups());
        $this->assertSame([], $access->missingGroups());
        try {
            $access->setGroups(['rh', 'ti'], ['rh']);
            $this->fail('a group that no longer exists cannot be added again');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(['rh'], $access->groups());
    }

    /**
     * Optimistic control: the caller says which list it changed, and a list changed meanwhile (another tab, another
     * administrator) is a conflict, so a removed group is never put back by a stale save.
     */
    public function testAStaleListIsAConflictAndNothingIsWritten(): void {
        $access = $this->access();
        $access->setGroups(['rh', 'ti'], []);
        $access->setGroups(['ti'], ['ti', 'rh']);
        try {
            $access->setGroups(['rh', 'ti'], ['rh', 'ti']);
            $this->fail('a save based on an old list must be refused');
        } catch (\OCA\Mcp\Service\LogsGroupsConflict) {
        }
        $this->assertSame(['ti'], $access->groups());
    }

    public function testAGarbledStoredValueReadsAsNoGroup(): void {
        $this->appConfig->stored['mcp'][LogsAccess::GROUPS_KEY] = '{not json';
        $this->assertSame([], $this->access()->groups());
        $this->appConfig->stored['mcp'][LogsAccess::GROUPS_KEY] = '["ti", 3, ""]';
        $this->assertSame(['ti'], $this->access()->groups());
    }

    /** The core writes to syslog, errorlog or systemd without a file to read back: then nobody passes, not even an admin. */
    public function testWithoutAFileLogNobodyPasses(): void {
        foreach (['syslog', 'errorlog', 'systemd', 'SYSLOG'] as $type) {
            $this->store->system['log_type'] = $type;
            $access = $this->access();
            $this->assertFalse($access->available(), $type);
            $this->assertSame(strtolower($type), $access->logType());
            $this->assertFalse($access->permits('root'), $type);
        }
    }

    /** Any other value, the default included, is the file writer: the core's LogFactory falls back to it too. */
    public function testTheFileLogIsTheDefault(): void {
        $this->assertTrue($this->access()->available());
        $this->assertSame('file', $this->access()->logType());
        $this->store->system['log_type'] = 'owncloud';
        $this->assertTrue($this->access()->available());
    }

    /**
     * Two workers read the same list; the second save, based on what it read before the first one wrote, must see the
     * written list inside the lock and be refused, not trust the copy its request cached.
     */
    public function testTheCheckReadsTheStoredListInsideTheLockAndNotTheRequestCache(): void {
        $first = $this->access();
        $second = $this->access();
        $first->setGroups(['rh', 'ti'], []);
        $this->assertSame(['rh', 'ti'], $second->groups(), 'the second worker caches the list now');

        $first->setGroups(['ti'], ['rh', 'ti']);
        try {
            $second->setGroups(['rh', 'ti'], ['rh', 'ti']);
            $this->fail('the revocation of rh must not be undone');
        } catch (LogsGroupsConflict) {
        }
        $this->assertSame(['ti'], json_decode($this->appConfig->stored['mcp'][LogsAccess::GROUPS_KEY], true));
        $this->assertSame([], $this->locks->held, 'the lock is released after a conflict too');
    }

    /** Interleaving: while one worker holds the lock between its check and its write, another save is refused. */
    public function testASaveWhileAnotherHoldsTheLockIsAConflict(): void {
        $first = $this->access();
        $second = $this->access();
        $first->setGroups(['rh', 'ti'], []);
        $refused = false;
        $this->locks->onAcquire = function () use ($second, &$refused): void {
            try {
                $second->setGroups(['rh', 'ti'], ['rh', 'ti']);
            } catch (LogsGroupsConflict) {
                $refused = true;
            }
        };

        $first->setGroups(['ti'], ['rh', 'ti']);

        $this->assertTrue($refused);
        $this->assertSame(['ti'], $this->access()->groups());
        $this->assertSame([LogsAccess::LOCK_KEY, LogsAccess::LOCK_KEY], $this->locks->acquired);
        $this->assertSame([], $this->locks->held);
    }

    /** An invalid group releases the lock and writes nothing. */
    public function testTheLockIsReleasedWhenTheListIsRefused(): void {
        $access = $this->access();
        try {
            $access->setGroups(['ghost'], []);
            $this->fail('an unknown group must be refused');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame([], $this->locks->held);
        $this->assertSame([], $this->appConfig->stored);
    }

    /** Group ids are strings: "1" and "01" are two groups, in the store and in the comparison alike. */
    public function testNumericLookingIdsAreComparedAsStrings(): void {
        $access = $this->access();
        $access->setGroups(['01', '1', '1'], []);
        $this->assertSame(['01', '1'], $this->access()->groups());
        $access->setGroups(['01', '1'], ['1', '01']);
        foreach ([['1'], ['01'], ['1', '1'], ['01', '01']] as $previous) {
            try {
                $access->setGroups(['1'], $previous);
                $this->fail(json_encode($previous) . ' is not the stored list');
            } catch (LogsGroupsConflict) {
            }
        }
        $access->setGroups(['1'], ['1', '01']);
        $this->assertSame(['1'], $this->access()->groups());
        try {
            $access->setGroups(['01'], ['01']);
            $this->fail('the stored list is ["1"], not ["01"]');
        } catch (LogsGroupsConflict) {
        }
        $this->assertTrue($this->access()->permits('uno'));
        $this->assertFalse($this->access()->permits('zero-uno'));
    }

    public function testPreviousMustListNonEmptyStrings(): void {
        $access = $this->access();
        foreach ([[1], [''], [['ti']], [null]] as $previous) {
            try {
                $access->setGroups(['ti'], $previous);
                $this->fail(json_encode($previous) . ' must be refused');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $access->groups());
    }
}
