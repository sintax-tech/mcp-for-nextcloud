<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** Builds an IConfig mock backed by an array, standing in for Nextcloud's persisted config. */
final class InMemoryConfig {
    public array $app = [];
    public array $user = [];

    public function mock(TestCase $test): IConfig {
        $config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $this->app[$app][$key] ?? $default);
        $config->method('setAppValue')->willReturnCallback(function ($app, $key, $value): void { $this->app[$app][$key] = $value; });
        $config->method('getUserValue')->willReturnCallback(fn ($uid, $app, $key, $default = '') => $this->user[$uid][$app][$key] ?? $default);
        $config->method('setUserValue')->willReturnCallback(function ($uid, $app, $key, $value): void { $this->user[$uid][$app][$key] = $value; });
        return $config;
    }
}
