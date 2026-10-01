<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Calendar\CalendarWriteGate;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Users, groups and apps of a fake instance for the admin matrix tests. The user search mimics the
 * stable33 Database backend: one match on uid, display name or e-mail, ordered by display name.
 */
final class MatrixFixture {
    public InMemoryConfig $config;
    public GrantPolicy $policy;
    /** @var array<string, array{name:string, email:string, enabled:bool}> */
    public array $users = [];
    /** @var array<string, list<string>> members by group id */
    public array $groups = ['sales' => [], 'admin' => []];
    /** @var list<array{string, ?int, ?int}> searchDisplayName calls: pattern, limit, offset */
    public array $searches = [];
    public array $enabledApps = ['notes', 'calendar'];
    /** @var array<string, list<string>> optional per-app user allowlists */
    public array $appUsers = [];

    public function __construct(private TestCase $test) {
        $this->config = new InMemoryConfig();
        $this->policy = new GrantPolicy($this->config->mock($test));
    }

    public function addUser(string $uid, string $name, string $email = '', bool $enabled = true, array $groups = []): void {
        $this->users[$uid] = ['name' => $name, 'email' => $email, 'enabled' => $enabled];
        foreach ($groups as $gid) {
            $this->groups[$gid][] = $uid;
        }
    }

    public function matrix(): GrantMatrix {
        return new GrantMatrix($this->policy, $this->userManager(), $this->groupManager(), $this->appManager(), $this->calendarGate());
    }

    /**
     * Gate with no verification stored, so the admin catalog only offers reading by default.
     *
     * @return CalendarWriteGate
     */
    public function calendarGate(): CalendarWriteGate {
        $appConfig = $this->mock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $appManager = $this->mock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('');
        $config = $this->mock(\OCP\IConfig::class);
        $config->method('getSystemValueString')->willReturn('');
        return new CalendarWriteGate($appConfig, $appManager, $config);
    }

    public function userManager(): IUserManager {
        $manager = $this->mock(IUserManager::class);
        $manager->method('get')->willReturnCallback(fn (string $uid) => isset($this->users[$uid]) ? $this->user($uid) : null);
        $manager->method('searchDisplayName')->willReturnCallback(function (string $pattern, $limit = null, $offset = null): array {
            $this->searches[] = [$pattern, $limit, $offset];
            return array_slice(array_map(fn ($uid) => $this->user($uid), $this->matching($pattern)), (int)$offset, $limit);
        });
        $manager->method('countUsersTotal')->willReturnCallback(fn () => count($this->users));
        return $manager;
    }

    /** @return list<string> uids whose uid, name or e-mail contain the pattern, ordered by name */
    public function matching(string $pattern): array {
        $uids = array_keys(array_filter($this->users, fn ($u, $uid) => $pattern === ''
            || stripos($uid, $pattern) !== false || stripos($u['name'], $pattern) !== false || stripos($u['email'], $pattern) !== false, ARRAY_FILTER_USE_BOTH));
        usort($uids, fn ($a, $b) => strcasecmp($this->users[$a]['name'], $this->users[$b]['name']));
        return $uids;
    }

    private function groupManager(): IGroupManager {
        $manager = $this->mock(IGroupManager::class);
        $manager->method('get')->willReturnCallback(fn (string $gid) => isset($this->groups[$gid]) ? $this->group($gid) : null);
        $manager->method('search')->willReturnCallback(fn () => array_map(fn ($gid) => $this->group($gid), array_keys($this->groups)));
        $manager->method('displayNamesInGroup')->willReturnCallback(function ($gid, $search = '', $limit = -1, $offset = 0): array {
            $uids = array_values(array_intersect($this->matching($search), $this->groups[$gid] ?? []));
            $uids = array_slice($uids, $offset, $limit === -1 ? null : $limit);
            return array_combine($uids, array_map(fn ($uid) => $this->users[$uid]['name'], $uids)) ?: [];
        });
        return $manager;
    }

    private function appManager(): IAppManager {
        $apps = $this->mock(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturnCallback(fn (string $app) => in_array($app, $this->enabledApps, true));
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app, IUser $user) =>
            in_array($app, $this->enabledApps, true)
            && (!isset($this->appUsers[$app]) || in_array($user->getUID(), $this->appUsers[$app], true)));
        return $apps;
    }

    private function group(string $gid): IGroup {
        $group = $this->mock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getDisplayName')->willReturn(ucfirst($gid));
        $group->method('count')->willReturnCallback(fn () => count($this->groups[$gid]));
        return $group;
    }

    private function user(string $uid): IUser {
        $user = $this->mock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($this->users[$uid]['name']);
        $user->method('getEMailAddress')->willReturn($this->users[$uid]['email']);
        $user->method('isEnabled')->willReturn($this->users[$uid]['enabled']);
        return $user;
    }

    private function mock(string $class): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
    }
}
