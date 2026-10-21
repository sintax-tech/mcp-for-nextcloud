<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/** Explicit unrestricted policy for tests focused on other Files responsibilities. */
final class FilesGuards {
    public static function unrestricted(TestCase $test, ?IConfig $config = null, ?ISystemTagObjectMapper $tags = null, ?IRootFolder $root = null, ...$options): FilesAccessGuard {
        $mock = static fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $config ??= (new InMemoryConfig())->mock($test);
        $tags ??= $mock(ISystemTagObjectMapper::class);
        $root ??= $mock(IRootFolder::class);
        $plain = new VisibilityGuard($config, $tags, $root, ...$options);
        return new FilesAccessGuard(FilesScopes::over($test, $plain, $root, static fn () => null), $config, $tags, $root, ...$options);
    }
}
