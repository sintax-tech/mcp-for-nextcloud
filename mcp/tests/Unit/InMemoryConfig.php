<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\Config\IUserConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** Builds an IConfig mock backed by an array, standing in for Nextcloud's persisted config. */
final class InMemoryConfig {
    public array $app = [];
    public array $user = [];
    /** @var array<string, string|int> system values served by getSystemValueString and getSystemValueInt */
    public array $system = [];
    /** @var list<array{string, string, string}> every [scope, app, key] read or written ('app' or 'user') */
    public array $accessed = [];
    /** Number of getUserValueForUsers calls. */
    public int $batchCalls = 0;
    /** Number of getUsersForUserValue calls, the flagged-user search of Nextcloud 31. */
    public int $legacySearches = 0;
    /** @var array<string, array{id:string, kind:string, status:string, payload:array, createdAt:int, attempts:int}> policy events by id */
    public array $events = [];
    /** Clock of the policy writer and of its events. */
    public int $now = 1_800_000_000;
    /** Policy revision of the writer built by policy(). */
    public int $revision = 0;
    /** Global auth epoch of the writer built by policy(). */
    public int $authEpoch = 0;
    /** @var array<string, array{issue_seq:string, revoked_below:string}> OAuth generations by uid; absent = (1, 0) */
    public array $generations = [];
    /** @var (\Closure(string):bool)|null which uids the writer's user manager knows; null knows every uid */
    public ?\Closure $userExists = null;
    /** @var list<\OCP\GroupInterface> group backends the resolver of policy() reads memberships and sources from */
    public array $groupBackends = [];

    /** @var \WeakMap<IConfig, IUserConfig>|null the IUserConfig double that serves the same data as each IConfig mock */
    private static ?\WeakMap $userConfigs = null;
    private static ?\WeakMap $stores = null;
    private static ?\WeakMap $services = null;
    private static ?\WeakMap $states = null;
    private static ?\WeakMap $authUsers = null;
    private static ?\WeakMap $processors = null;
    private static ?\WeakMap $resolvers = null;
    private static ?\WeakMap $groupDatabases = null;

    /** @return \OCA\Mcp\Db\AuthUsersMapper the OAuth generations the writer of $config uses (over $generations) */
    public static function authUsers(IConfig $config): \OCA\Mcp\Db\AuthUsersMapper {
        self::services($config);
        return self::$authUsers[$config];
    }

    /** @return \OCA\Mcp\Access\Policy\Events\PolicyEventProcessor the event delivery of the writer of $config */
    public static function processor(IConfig $config): \OCA\Mcp\Access\Policy\Events\PolicyEventProcessor {
        self::services($config);
        return self::$processors[$config];
    }

    /** @return \OCA\Mcp\Db\PolicyStateMapper the state the writer of $config reads, for a controller under test */
    public static function state(IConfig $config): \OCA\Mcp\Db\PolicyStateMapper {
        self::services($config);
        return self::$states[$config];
    }

    /**
     * A cause processor for a test whose users have no recheck causes: the preflight does nothing and issuance gets a
     * classification that would cut every cause (none is ever read). Causes are proven in CauseProcessingTest.
     */
    public static function noCauses(TestCase $test): \OCA\Mcp\OAuth\Revocation\CauseProcessor {
        $causes = (new \ReflectionMethod($test, 'createMock'))->invoke($test, \OCA\Mcp\OAuth\Revocation\CauseProcessor::class);
        $causes->method('classifier')->willReturn(static fn (string $uid, array $rows): array => array_fill_keys(array_column($rows, 'causeKey'), true));
        return $causes;
    }

    /**
     * A GrantPolicy over an IConfig mock of this class and the IUserConfig double that goes with it
     * (the policy lists flagged users through IUserConfig::searchUsersByValueString).
     */
    public static function policy(IConfig $config, \OCA\Mcp\OAuth\OAuthStore $store): \OCA\Mcp\Access\Grants\GrantPolicy {
        $services = self::services($config, $store);
        return new \OCA\Mcp\Access\Grants\GrantPolicy($config, self::$userConfigs[$config], $services->grants, $services->service, self::resolver($config), $services->transaction);
    }

    /**
     * The real resolver over the data of $config: no group backends unless $groupBackends lists some, grant rows and
     * lifecycles in an SQLite database of their own (groupDatabase()). Without group rows its answer is the 1.0.8 one.
     */
    public static function resolver(IConfig $config): \OCA\Mcp\Access\Policy\Resolution\PolicyResolver {
        self::$resolvers ??= new \WeakMap();
        self::$groupDatabases ??= new \WeakMap();
        if (isset(self::$resolvers[$config])) {
            return self::$resolvers[$config];
        }
        $writer = self::services($config);
        [$data, $test] = self::$stores[$config];
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $groups = self::$groupDatabases[$config] = new SqliteDatabase($test, [
            new \OCA\Mcp\Migration\Version001100Date20261014000000(), new \OCA\Mcp\Migration\Version001100Date20261015010000(),
            new \OCA\Mcp\Migration\Version001100Date20261015020000()]);
        $users = $mock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(static function (string $uid) use ($data, $mock): ?\OCP\IUser {
            if ($data->userExists !== null && !($data->userExists)($uid)) {
                return null;
            }
            $user = $mock(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });
        return self::$resolvers[$config] = self::resolverOver($test, self::state($config), $writer->transaction, $config,
            self::$userConfigs[$config], $mock(\OCP\IAppConfig::class), $users, $groups->connection(),
            static fn (): array => $data->groupBackends);
    }

    /**
     * The real resolver over the given collaborators, for a test that builds its own writer: grant rows and lifecycles
     * in $db, memberships and sources from $backends, the apps of every module off and no logs role.
     *
     * @param \Closure(): list<\OCP\GroupInterface> $backends the group backends, read on every call
     */
    public static function resolverOver(TestCase $test, \OCA\Mcp\Db\PolicyStateMapper $state, \OCA\Mcp\Access\Policy\Transactions\PolicyTransaction $writer,
        IConfig $config, IUserConfig $userConfig, \OCP\IAppConfig $appConfig, \OCP\IUserManager $users, \OCP\IDBConnection $db,
        ?\Closure $backends = null): \OCA\Mcp\Access\Policy\Resolution\PolicyResolver {
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $manager = $mock(\OCP\IGroupManager::class);
        $manager->method('getBackends')->willReturnCallback($backends ?? static fn (): array => []);
        $logs = $mock(\OCA\Mcp\Access\Policy\Resolution\LogsRoleReader::class);
        $logs->method('read')->willReturn(['available' => false, 'permitted' => false]);
        $life = new \OCA\Mcp\Db\GroupLifeMapper($db);
        return new \OCA\Mcp\Access\Policy\Resolution\PolicyResolver($state, $writer, $config, $userConfig, $appConfig, $users,
            new \OCA\Mcp\Access\Grants\Groups\MembershipReader($manager), new \OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier($manager),
            new \OCA\Mcp\Db\GroupGrantsMapper($db), $life,
            new \OCA\Mcp\Access\Grants\Groups\GroupTrustObserver($db, $writer, $life, new \Psr\Log\NullLogger()),
            $mock(\OCP\App\IAppManager::class), $logs, new \OCA\Mcp\Db\ResourceScopeMapper($db));
    }

    /**
     * The logs role gate over a fresh membership of $groups (members by group id, read on every check; 'admin' are
     * the administrators), as the server builds it: LogsRoleReader and MembershipReader over one Database backend.
     *
     * @param \Closure(): array<string, list<string>> $groups
     */
    public static function logsAccess(TestCase $test, IConfig $config, \OCP\IGroupManager $manager, \OCA\Mcp\Db\LogsGroupsMapper $mapper,
        \OCP\Lock\ILockingProvider $locks): \OCA\Mcp\Access\Logs\LogsAccess {
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $remote = $mock(\OCP\Security\Ip\IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturn(true);
        $users = $mock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(static function (string $uid) use ($mock): \OCP\IUser {
            $user = $mock(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $roles = new \OCA\Mcp\Access\Policy\Resolution\LogsRoleReader($config, $mapper, $manager, $remote, $mock(\OCP\IUserSession::class));
        return new \OCA\Mcp\Access\Logs\LogsAccess($config, $manager, $mapper, $locks, self::services($config)->transaction, $roles,
            new \OCA\Mcp\Access\Grants\Groups\MembershipReader($manager), $users);
    }

    /**
     * Stores a group policy as a save of ticket 08 leaves it, in the database the resolver of $config reads: one row
     * per grant and a lifecycle in $state, with a new gen.
     *
     * @param array<string, list<string>> $grants operations by module, '_system' => ['eligible'] for eligibility
     * @return string the gen of the lifecycle
     */
    public static function groupPolicy(IConfig $config, string $gid, array $grants, string $state = 'active'): string {
        return self::storeGroupPolicy(self::groupDatabase($config), $gid, $grants, $state);
    }

    /** @see groupPolicy(), over any database with the group tables */
    public static function storeGroupPolicy(SqliteDatabase $database, string $gid, array $grants, string $state = 'active'): string {
        $key = \OCA\Mcp\Db\IdentityKey::group($gid);
        $database->dbal->delete('mcp_group_grants', ['group_key' => $key]);
        foreach ($grants as $module => $operations) {
            foreach ($operations as $operation) {
                $database->dbal->insert('mcp_group_grants', ['group_key' => $key, 'group_id' => $gid, 'module' => $module, 'operation' => $operation]);
            }
        }
        $database->dbal->delete('mcp_group_life', ['group_key' => $key]);
        $gen = bin2hex(random_bytes(16));
        $database->dbal->insert('mcp_group_life', ['group_key' => $key, 'group_id' => $gid, 'state' => $state, 'gen' => $gen, 'policy_rev' => 0]);
        return $gen;
    }

    /**
     * Moves a lifecycle to $state with a new gen, as another process would (a pending_delete moves no revision).
     *
     * @return string the new gen
     */
    public static function storeGroupState(SqliteDatabase $database, string $gid, string $state): string {
        $gen = bin2hex(random_bytes(16));
        $database->dbal->update('mcp_group_life', ['state' => $state, 'gen' => $gen], ['group_key' => \OCA\Mcp\Db\IdentityKey::group($gid)]);
        return $gen;
    }

    /** @return SqliteDatabase the group rows and lifecycles the resolver of $config reads */
    public static function groupDatabase(IConfig $config): SqliteDatabase {
        self::resolver($config);
        return self::$groupDatabases[$config];
    }

    /**
     * The policy writer over an IConfig mock of this class: one per IConfig, so the policy, the logs gate and the
     * controller of a test share its revision ($revision) and its events ($events, appended at $now). The database
     * is a mock without transactions: atomicity and rollback are proven against SQLite and the real server.
     */
    public static function services(IConfig $config, ?\OCA\Mcp\OAuth\OAuthStore $store = null): \OCA\Mcp\Tests\Support\Access\Policy\PolicyServices {
        self::$services ??= new \WeakMap();
        self::$states ??= new \WeakMap();
        if (isset(self::$services[$config])) {
            return self::$services[$config];
        }
        $test = self::$stores[$config][1];
        $data = self::$stores[$config][0];
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $db = $mock(\OCP\IDBConnection::class);
        $state = $mock(\OCA\Mcp\Db\PolicyStateMapper::class);
        $state->method('state')->willReturnCallback(static function () use ($data): array {
            return ['revision' => (string)$data->revision, 'lock_seq' => '0', 'auth_epoch' => (string)$data->authEpoch];
        });
        $state->method('compareAndSet')->willReturnCallback(static function (string $expected) use ($data): void {
            if ($expected !== (string)$data->revision) {
                throw new \OCA\Mcp\Access\Policy\Transactions\PolicyConflict();
            }
            ++$data->revision;
        });
        $state->method('advanceAuthEpoch')->willReturnCallback(static fn (string $expected): string => (string)++$data->authEpoch);
        $authUsers = $mock(\OCA\Mcp\Db\AuthUsersMapper::class);
        $generations = static fn (string $uid): array => $data->generations[$uid] ?? ['issue_seq' => '1', 'revoked_below' => '0'];
        $authUsers->method('generations')->willReturnCallback($generations);
        $authUsers->method('issue')->willReturnCallback(static function (string $uid) use ($data, $generations): string {
            $data->generations[$uid] = ['issue_seq' => (string)((int)$generations($uid)['issue_seq'] + 1)] + $generations($uid);
            return $data->generations[$uid]['issue_seq'];
        });
        $authUsers->method('cut')->willReturnCallback(static function (string $uid, string $cut) use ($data, $generations): bool {
            if ((int)$cut <= (int)$generations($uid)['revoked_below']) {
                return false;
            }
            $data->generations[$uid] = ['revoked_below' => $cut] + $generations($uid);
            return true;
        });
        $rechecks = $mock(\OCA\Mcp\Db\RecheckMapper::class);
        $rechecks->method('causes')->willReturn([]);
        $users = $mock(\OCP\IUserManager::class);
        $user = $mock(\OCP\IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $users->method('get')->willReturnCallback(static fn (string $uid) => $data->userExists === null || ($data->userExists)($uid) ? $user : null);
        $appConfig = $mock(\OCP\IAppConfig::class);
        $appConfig->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $data->app[$app] ?? []));
        self::$states[$config] = $state;
        self::$authUsers ??= new \WeakMap();
        self::$authUsers[$config] = $authUsers;
        $events = $mock(\OCA\Mcp\Db\PolicyEventsMapper::class);
        $events->method('append')->willReturnCallback(static function (string $id, string $kind, array $payload) use ($data): void {
            $data->events[$id] = ['id' => $id, 'kind' => $kind, 'status' => 'pending', 'payload' => $payload, 'createdAt' => $data->now, 'attempts' => 0];
        });
        $events->method('find')->willReturnCallback(static fn (string $id): ?array => $data->events[$id] ?? null);
        $time = $mock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(static fn (): int => $data->now);
        $events->method('complete')->willReturnCallback(static function (string $id) use ($data): bool {
            $pending = ($data->events[$id]['status'] ?? null) === 'pending';
            if ($pending) {
                $data->events[$id]['status'] = 'delivered';
            }
            return $pending;
        });
        $store ??= new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore();
        // The real delivery over the store double: a revocation's cleanup removes the store rows it covers.
        $processor = new \OCA\Mcp\Access\Policy\Events\PolicyEventProcessor($events, $store, new \Psr\Log\NullLogger(), $time);
        self::$processors ??= new \WeakMap();
        self::$processors[$config] = $processor;
        $transaction = new \OCA\Mcp\Access\Policy\Transactions\PolicyTransaction($db, $state, $events, self::$userConfigs[$config], $appConfig, $processor);
        return self::$services[$config] = new \OCA\Mcp\Tests\Support\Access\Policy\PolicyServices($transaction, $config, $appConfig, $users, $time,
            $state, $events, $authUsers, $rechecks, new \OCA\Mcp\Db\GroupGrantsMapper($db), new \OCA\Mcp\Db\GroupLifeMapper($db), $store,
            new \OCA\Mcp\Db\ResourceScopeMapper($db));
    }

    /**
     * An IAppConfig mock that reads and writes the same app values as the IConfig mocks of this class, with no cache
     * of its own: the lazy value of the self-hosted clients is read and written here, over the same data.
     */
    public function appConfig(TestCase $test): \OCP\IAppConfig {
        $appConfig = (new \ReflectionMethod($test, 'createMock'))->invoke($test, \OCP\IAppConfig::class);
        $appConfig->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $this->app[$app] ?? []));
        $appConfig->method('setValueString')->willReturnCallback(function ($app, $key, $value): bool {
            $this->accessed[] = ['app', $app, $key];
            $this->app[$app][$key] = $value;
            return true;
        });
        $appConfig->method('deleteKey')->willReturnCallback(function ($app, $key): void {
            $this->accessed[] = ['app', $app, $key];
            unset($this->app[$app][$key]);
        });
        $appConfig->method('getValueString')->willReturnCallback(function ($app, $key, $default = ''): string {
            $this->accessed[] = ['app', $app, $key];
            return (string)($this->app[$app][$key] ?? $default);
        });
        return $appConfig;
    }

    public function mock(TestCase $test): IConfig {
        $config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IConfig::class);
        $userConfig = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IUserConfig::class);
        self::$userConfigs ??= new \WeakMap();
        self::$userConfigs[$config] = $userConfig;
        self::$stores ??= new \WeakMap();
        self::$stores[$config] = [$this, $test];
        $userConfig->method('searchUsersByValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $caseInsensitive = false): \Generator {
            $this->accessed[] = ['user', $app, $key];
            yield from array_keys(array_filter($this->user, static fn (array $apps) => ($apps[$app][$key] ?? null) === $value));
        });
        $config->method('getSystemValueString')->willReturnCallback(function ($key, $default = '') {
            return (string)($this->system[$key] ?? $default);
        });
        $config->method('getSystemValueInt')->willReturnCallback(function ($key, $default = 0) {
            return (int)($this->system[$key] ?? $default);
        });
        $config->method('getAppValue')->willReturnCallback(function ($app, $key, $default = '') {
            $this->accessed[] = ['app', $app, $key];
            return $this->app[$app][$key] ?? $default;
        });
        $config->method('setAppValue')->willReturnCallback(function ($app, $key, $value): void {
            $this->accessed[] = ['app', $app, $key];
            $this->app[$app][$key] = $value;
        });
        $config->method('deleteAppValue')->willReturnCallback(function ($app, $key): void {
            $this->accessed[] = ['app', $app, $key];
            unset($this->app[$app][$key]);
        });
        $config->method('getUserValue')->willReturnCallback(function ($uid, $app, $key, $default = '') {
            $this->accessed[] = ['user', $app, $key];
            return $this->user[$uid][$app][$key] ?? $default;
        });
        $config->method('getUserValueForUsers')->willReturnCallback(function ($app, $key, $uids) {
            $this->accessed[] = ['user', $app, $key];
            $this->batchCalls++;
            $out = [];
            foreach ($uids as $uid) {
                if (isset($this->user[$uid][$app][$key])) {
                    $out[$uid] = $this->user[$uid][$app][$key];
                }
            }
            return $out;
        });
        $config->method('getUsersForUserValue')->willReturnCallback(function ($app, $key, $value): array {
            $this->accessed[] = ['user', $app, $key];
            $this->legacySearches++;
            return array_keys(array_filter($this->user, static fn (array $apps) => ($apps[$app][$key] ?? null) === $value));
        });
        $config->method('deleteUserValue')->willReturnCallback(function ($uid, $app, $key): void {
            $this->accessed[] = ['user', $app, $key];
            unset($this->user[$uid][$app][$key]);
        });
        $config->method('setUserValue')->willReturnCallback(function ($uid, $app, $key, $value): void {
            $this->accessed[] = ['user', $app, $key];
            $this->user[$uid][$app][$key] = $value;
        });
        return $config;
    }
}
