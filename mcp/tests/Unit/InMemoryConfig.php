<?php
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

    /** @var \WeakMap<IConfig, IUserConfig>|null the IUserConfig double that serves the same data as each IConfig mock */
    private static ?\WeakMap $userConfigs = null;

    /**
     * A GrantPolicy over an IConfig mock of this class and the IUserConfig double that goes with it
     * (the policy lists flagged users through IUserConfig::searchUsersByValueString).
     */
    public static function policy(IConfig $config, \OCA\Mcp\OAuth\OAuthStore $store): \OCA\Mcp\Service\GrantPolicy {
        return new \OCA\Mcp\Service\GrantPolicy($config, $store, self::$userConfigs[$config]);
    }

    public function mock(TestCase $test): IConfig {
        $config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IConfig::class);
        $userConfig = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IUserConfig::class);
        self::$userConfigs ??= new \WeakMap();
        self::$userConfigs[$config] = $userConfig;
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
        $config->method('setUserValue')->willReturnCallback(function ($uid, $app, $key, $value): void {
            $this->accessed[] = ['user', $app, $key];
            $this->user[$uid][$app][$key] = $value;
        });
        return $config;
    }
}
