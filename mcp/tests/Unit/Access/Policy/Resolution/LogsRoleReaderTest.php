<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy\Resolution;

use OCA\Mcp\Db\LogsGroupsMapper;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Policy\Resolution\LogsRoleReader;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Group\Backend\IIsAdminBackend;
use OCP\GroupInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Security\Ip\IRemoteAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogsRoleReaderTest extends TestCase {
    /** @var list<string> */
    private array $ids = [];

    public static function cases(): iterable {
        foreach (['file', 'syslog', 'errorlog', 'systemd', 'custom'] as $type) {
            foreach ([true, false] as $remote) {
                foreach ([true, false] as $nativeBackend) {
                    foreach ([[], ['admin'], ['logs'], ['admin', 'logs'], ['unrelated']] as $ids) {
                        foreach (['alice', 'bob', null] as $sessionUid) {
                            foreach ([true, false] as $strict) {
                                yield [$type, $remote, $nativeBackend, $ids, $sessionUid, $strict];
                            }
                        }
                    }
                }
            }
        }
    }

    private function session(?string $uid): IUserSession {
        $session = $this->createMock(IUserSession::class);
        $user = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
        }
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    /**
     * A transcription of OC\Group\Manager::isAdmin and ::isInGroup (stable32–35), not of the reader: the
     * remote-address restriction only applies to the session user; isInGroup is loose on NC32–34, strict on NC35.
     */
    private function coreManager(IRemoteAddress $remote, IUserSession $session, array $backends, bool $strict): IGroupManager {
        $manager = $this->createMock(IGroupManager::class);
        $isInGroup = fn ($userId, $group): bool => $strict ? in_array($group, $this->ids, true) : in_array($group, $this->ids);
        $manager->method('isInGroup')->willReturnCallback($isInGroup);
        $manager->method('isAdmin')->willReturnCallback(static function ($userId) use ($remote, $session, $backends, $isInGroup): bool {
            if (!$remote->allowsAdminActions()
                && ($userId === $session->getUser()?->getUID())) {
                return false;
            }
            foreach ($backends as $backend) {
                if (is_string($userId) && $backend->implementsActions(GroupInterface::IS_ADMIN) && $backend->isAdmin($userId)) {
                    return true;
                }
            }
            return $isInGroup($userId, 'admin');
        });
        $manager->method('getBackends')->willReturn($backends);
        return $manager;
    }

    /**
     * @return array{0:\Closure(string):array{available:bool,permitted:bool}, 1:LogsRoleReader, 2:LogsAccess} the 1.0.8 gate
     *     (a transcription of LogsAccess::permits() before ticket 09, over the core manager), the reader, and the
     *     LogsAccess of ticket 09, which reads the role through the reader on a fresh membership
     */
    private function pair(string $type, bool $remoteAllowed, bool $nativeAdmin, ?string $sessionUid, bool $strict, array $listed): array {
        $data = new InMemoryConfig();
        $data->system['log_type'] = $type;
        $config = $data->mock($this);
        $mapper = $this->createMock(LogsGroupsMapper::class);
        $mapper->method('state')->willReturn(['groups' => $listed, 'version' => 1]);
        $backend = $this->createMockForIntersectionOfInterfaces([GroupInterface::class, IIsAdminBackend::class]);
        $backend->method('implementsActions')->with(GroupInterface::IS_ADMIN)->willReturn(true);
        $backend->method('isAdmin')->with('alice')->willReturn($nativeAdmin);
        $backend->method('getUserGroups')->willReturnCallback(fn (): array => $this->ids);
        $remote = $this->createMock(IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturn($remoteAllowed);
        $session = $this->session($sessionUid);
        $manager = $this->coreManager($remote, $session, [$backend], $strict);
        $reader = new LogsRoleReader($config, $mapper, $manager, $remote, $session);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $access = new LogsAccess($config, $manager, $mapper, $this->createMock(ILockingProvider::class), $this->createMock(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class),
            $reader, new \OCA\Mcp\Access\Grants\Groups\MembershipReader($manager), $users);
        $available = !in_array($type, LogsAccess::NON_FILE_TYPES, true);
        $legacy = static function (string $uid) use ($available, $manager, $listed): array {
            if (!$available) {
                return ['available' => false, 'permitted' => false];
            }
            if ($manager->isAdmin($uid)) {
                return ['available' => true, 'permitted' => true];
            }
            foreach ($listed as $gid) {
                if ($manager->isInGroup($uid, $gid)) {
                    return ['available' => true, 'permitted' => true];
                }
            }
            return ['available' => true, 'permitted' => false];
        };
        return [$legacy, $reader, $access];
    }

    #[DataProvider('cases')]
    public function testFreshAdapterMatchesLegacyLogGate(string $type, bool $remoteAllowed, bool $nativeAdmin, array $ids,
        ?string $sessionUid, bool $strict): void {
        $this->ids = $ids;
        [$legacy, $reader, $access] = $this->pair($type, $remoteAllowed, $nativeAdmin, $sessionUid, $strict, ['logs']);
        $this->assertSame($legacy('alice'), $reader->read('alice', $ids));
        // LogsAccess now reads through the reader on a fresh membership: the same answer as the 1.0.8 gate.
        $this->assertSame($legacy('alice')['permitted'], $access->permits('alice'));
    }

    public function testAnotherUsersAdministrationIsNotSubjectToTheViewersRemoteAddress(): void {
        $this->ids = ['admin'];
        [$legacy, $reader, $access] = $this->pair('file', false, false, 'bob', false, []);
        $this->assertTrue($legacy('alice')['permitted']);
        $this->assertTrue($reader->read('alice', ['admin'])['permitted']);
        $this->assertTrue($access->permits('alice'));
        [$legacy, $reader, $access] = $this->pair('file', false, false, 'alice', false, []);
        $this->assertFalse($legacy('alice')['permitted']);
        $this->assertFalse($reader->read('alice', ['admin'])['permitted']);
        $this->assertFalse($access->permits('alice'));
    }

    /** Intentional, fail-closed divergence from the loose isInGroup of NC32–34: "01" and "1" are distinct groups. */
    public function testNumericGroupIdsCompareStrictlyOnEveryVersion(): void {
        $this->ids = ['1'];
        [$legacy, $reader, $access] = $this->pair('file', true, false, null, false, ['01']);
        $this->assertTrue($legacy('alice')['permitted'], 'NC32–34 core matches "01" == "1"');
        $this->assertFalse($reader->read('alice', ['1'])['permitted']);
        $this->assertFalse($access->permits('alice'), 'the one documented divergence, fail-closed');
        [$legacy, $reader, $access] = $this->pair('file', true, false, null, true, ['01']);
        $this->assertFalse($legacy('alice')['permitted']);
        $this->assertFalse($access->permits('alice'));
        $this->assertFalse($reader->read('alice', ['1'])['permitted']);
        $this->assertTrue($reader->read('alice', ['01'])['permitted']);
    }

    public function testSameReaderUsesNewMembershipRemoteAddressAndRoleStateOnEveryOperation(): void {
        $config = (new InMemoryConfig())->mock($this);
        $mapper = $this->createMock(LogsGroupsMapper::class);
        $mapper->method('state')->willReturn(['groups' => ['logs'], 'version' => 1]);
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('getBackends')->willReturn([]);
        $manager->expects($this->never())->method('isAdmin');
        $manager->expects($this->never())->method('isInGroup');
        $allowed = true;
        $remote = $this->createMock(IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturnCallback(static function () use (&$allowed): bool { return $allowed; });
        $reader = new LogsRoleReader($config, $mapper, $manager, $remote, $this->session('alice'));
        $this->assertTrue($reader->read('alice', ['admin'])['permitted']);
        $allowed = false;
        $this->assertFalse($reader->read('alice', ['admin'])['permitted']);
        // Existing explicit logs roles are independent of the admin-address gate.
        $this->assertTrue($reader->read('alice', ['logs'])['permitted']);
        $this->assertFalse($reader->read('alice', [])['permitted']);
        $this->assertFalse($reader->read(null, ['admin', 'logs'])['permitted']);
    }
}
