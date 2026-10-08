<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * App config shared by several workers, each with the per-request cache the core's AppConfig keeps: a worker reads
 * the stored values once and keeps them until clearCache(), while its own writes go through to the store at once.
 */
final class InMemoryAppConfig {
    /** @var array<string, array<string, string>> values in the database, shared by every worker */
    public array $stored = [];
    /** Number of clearCache() calls, across workers. */
    public int $cacheClears = 0;

    /** @return IAppConfig one request's view of the store */
    public function worker(TestCase $test): IAppConfig {
        $config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IAppConfig::class);
        $cache = null;
        $config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => isset($this->stored[$app][$key]));
        $config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use (&$cache): string {
            $cache ??= $this->stored;
            return $cache[$app][$key] ?? $default;
        });
        $config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use (&$cache): bool {
            $this->stored[$app][$key] = $value;
            if ($cache !== null) {
                $cache[$app][$key] = $value;
            }
            return true;
        });
        $config->method('deleteKey')->willReturnCallback(function (string $app, string $key) use (&$cache): void {
            unset($this->stored[$app][$key]);
            if ($cache !== null) {
                unset($cache[$app][$key]);
            }
        });
        $config->method('clearCache')->willReturnCallback(function () use (&$cache): void {
            $cache = null;
            $this->cacheClears++;
        });
        return $config;
    }
}
