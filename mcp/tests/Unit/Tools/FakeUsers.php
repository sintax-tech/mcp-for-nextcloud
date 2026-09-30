<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * IUserManager double serving a fixed set of users by uid, with the display names the tests assert on.
 * Kept next to FakeTree because the two always travel together.
 */
final class FakeUsers {
    /** Alice is the viewer the Files tests authenticate as; Pedro is who shared things with her. */
    public const DEFAULTS = ['alice' => 'Alice', 'pedro' => 'Pedro Almeida'];
    /**
     * @param TestCase $test test building the mocks
     * @param array<string, string> $displayNames display name by uid
     * @return IUserManager a manager that only knows those uids
     */
    public static function manager(TestCase $test, array $displayNames): IUserManager {
        $users = [];
        foreach ($displayNames as $uid => $displayName) {
            $user = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IUser::class);
            $user->method('getUID')->willReturn((string)$uid);
            $user->method('getDisplayName')->willReturn($displayName);
            $user->method('isEnabled')->willReturn(true);
            $users[(string)$uid] = $user;
        }
        $manager = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IUserManager::class);
        $manager->method('get')->willReturnCallback(static fn (string $uid) => $users[$uid] ?? null);
        return $manager;
    }
}
