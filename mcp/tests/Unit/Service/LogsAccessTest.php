<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The role gate of the logs module: only a Nextcloud administrator or a member of a group the administrator listed
 * may read the server log, and nobody may while the log is not a file the core can read back.
 */
final class LogsAccessTest extends TestCase {
    private InMemoryConfig $store;
    /** @var array<string, list<string>> members by group id */
    private array $groups = ['admin' => ['root'], 'ti' => ['tina'], 'rh' => ['rita']];

    protected function setUp(): void {
        $this->store = new InMemoryConfig();
    }

    private function access(): LogsAccess {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->groups['admin'], true));
        $groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($uid, $this->groups[$gid] ?? [], true));
        $groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => isset($this->groups[$gid]) ? $this->createMock(IGroup::class) : null);
        return new LogsAccess($this->store->mock($this), $groups);
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
        $this->assertSame(['rh', 'ti'], json_decode($this->store->app['mcp'][LogsAccess::GROUPS_KEY], true));
        $access->setGroups([]);
        $this->assertArrayNotHasKey(LogsAccess::GROUPS_KEY, $this->store->app['mcp'] ?? []);
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

    public function testAGarbledStoredValueReadsAsNoGroup(): void {
        $this->store->app['mcp'][LogsAccess::GROUPS_KEY] = '{not json';
        $this->assertSame([], $this->access()->groups());
        $this->store->app['mcp'][LogsAccess::GROUPS_KEY] = '["ti", 3, ""]';
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
}
