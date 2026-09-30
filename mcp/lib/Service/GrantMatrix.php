<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Read model of the admin permission matrix: one page of users (searched, optionally within a group)
 * with their eligibility, connection and grants loaded in batch. Only uid and display name identify a
 * user in the output; e-mail may match the search but is never returned.
 */
class GrantMatrix {
    /** Users per page. */
    public const PAGE_SIZE = 50;
    /** Groups listed in the group filter. */
    public const MAX_GROUPS = 200;
    /** Highest page accepted, to bound offsets. */
    public const MAX_PAGE = 10000;
    /** Longest search term accepted. */
    public const MAX_SEARCH = 100;
    /** Nextcloud app that provides each module of GrantPolicy::CATALOG. */
    public const MODULE_APPS = ['files' => 'files', 'notes' => 'notes', 'deck' => 'deck', 'calendar' => 'calendar', 'talk' => 'spreed'];

    public function __construct(
        private GrantPolicy $policy,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IAppManager $appManager,
    ) {}

    /**
     * @param string $search term matched by the user backends against uid, display name and e-mail
     * @param string $group group id to restrict to, '' for every user
     * @param int $page 1-based page number
     * @return array{users: list<array{uid:string, displayName:string, enabled:bool, eligible:bool, connected:bool, grants:array<string, array<string, bool>>}>, page:int, pageSize:int, hasMore:bool, total:int|null, catalog:array<string, list<string>>, appsEnabled:array<string, bool>, groups:list<array{id:string, displayName:string}>, serviceEnabled:bool}
     * @throws InvalidArgumentException for a page out of range, an oversized term or an unknown group
     */
    public function page(string $search, string $group, int $page): array {
        $search = trim($search);
        if ($page < 1 || $page > self::MAX_PAGE || mb_strlen($search) > self::MAX_SEARCH) {
            throw new InvalidArgumentException('Invalid request');
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        [$users, $total] = $group === '' ? $this->allUsers($search, $offset) : $this->groupUsers($group, $search, $offset);
        $hasMore = count($users) > self::PAGE_SIZE;
        $users = array_slice($users, 0, self::PAGE_SIZE);
        return [
            'users' => $this->rows($users),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'hasMore' => $hasMore,
            'total' => $total,
            'catalog' => GrantPolicy::CATALOG,
            'appsEnabled' => $this->appsEnabled(),
            'groups' => $this->groups(),
            'serviceEnabled' => $this->policy->globalEnabled(),
        ];
    }

    /**
     * @param list<IUser> $users users to render
     * @return list<array{uid:string, displayName:string, enabled:bool, eligible:bool, connected:bool, grants:array<string, array<string, bool>>}>
     */
    public function rows(array $users): array {
        $state = $this->policy->forUsers(array_map(static fn (IUser $user) => $user->getUID(), $users));
        return array_map(static fn (IUser $user) => [
            'uid' => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'enabled' => $user->isEnabled(),
        ] + $state[$user->getUID()], $users);
    }

    /** @return array<string, bool> whether each module's app is enabled for anyone on the server */
    public function appsEnabled(): array {
        return array_map(fn (string $app) => $app === 'files' || $this->appManager->isEnabledForAnyone($app), self::MODULE_APPS);
    }

    /** @return list<array{id:string, displayName:string}> groups for the filter, at most MAX_GROUPS */
    public function groups(): array {
        return array_map(static fn ($group) => ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()],
            array_values($this->groupManager->search('', self::MAX_GROUPS)));
    }

    /**
     * One backend search on uid, display name and e-mail (stable33 Database::getDisplayNames), fetching one
     * extra user to know whether another page exists.
     *
     * @return array{0: list<IUser>, 1: int|null} users and the total when it is cheap to know
     */
    private function allUsers(string $search, int $offset): array {
        $users = array_values($this->userManager->searchDisplayName($search, self::PAGE_SIZE + 1, $offset));
        $total = null;
        if ($search === '') {
            $count = $this->userManager->countUsersTotal();
            $total = is_int($count) ? $count : null;
        }
        return [$users, $total];
    }

    /**
     * @return array{0: list<IUser>, 1: int|null} users of the group and its size when there is no search
     * @throws InvalidArgumentException for an unknown group
     */
    private function groupUsers(string $gid, string $search, int $offset): array {
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        $users = [];
        foreach (array_keys($this->groupManager->displayNamesInGroup($gid, $search, self::PAGE_SIZE + 1, $offset)) as $uid) {
            $user = $this->userManager->get((string)$uid);
            if ($user !== null) {
                $users[] = $user;
            }
        }
        $count = $search === '' ? $group->count() : false;
        return [$users, is_int($count) ? $count : null];
    }
}
