<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** Builds an IConfig mock backed by an array, standing in for Nextcloud's persisted config. */
final class InMemoryConfig {
    public array $app = [];
    public array $user = [];
    /** @var array<string, string> system values served by getSystemValueString */
    public array $system = [];
    /** @var list<array{string, string, string}> every [scope, app, key] read or written ('app' or 'user') */
    public array $accessed = [];
    /** Number of getUserValueForUsers calls. */
    public int $batchCalls = 0;

    public function mock(TestCase $test): IConfig {
        $config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(function ($key, $default = '') {
            return $this->system[$key] ?? $default;
        });
        $config->method('getAppValue')->willReturnCallback(function ($app, $key, $default = '') {
            $this->accessed[] = ['app', $app, $key];
            return $this->app[$app][$key] ?? $default;
        });
        $config->method('setAppValue')->willReturnCallback(function ($app, $key, $value): void {
            $this->accessed[] = ['app', $app, $key];
            $this->app[$app][$key] = $value;
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
        $config->method('setUserValue')->willReturnCallback(function ($uid, $app, $key, $value): void {
            $this->accessed[] = ['user', $app, $key];
            $this->user[$uid][$app][$key] = $value;
        });
        return $config;
    }
}
