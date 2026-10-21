<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Platform\FileAccess\References;

use OCA\Mcp\Tests\Support\Platform\FileAccess\References\UnrestrictedFileReferenceScope;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Platform\FileAccess\References\FileReferenceScope;
use OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/** Builds the structured-reference filter for module tests. */
final class OutputReferenceFilters {
    /**
     * The exclusion guard of an instance without any exclusion tag: it hides nothing, for tests of the other gates.
     *
     * @param TestCase $test test creating the doubles
     * @return VisibilityGuard the guard
     */
    public static function openGuard(TestCase $test): VisibilityGuard {
        return new VisibilityGuard((new InMemoryConfig())->mock($test), self::mock($test, ISystemTagObjectMapper::class));
    }

    /**
     * The filter as it runs with no exclusion and no Files scope: every reference passes untouched.
     *
     * @param TestCase $test test creating the doubles
     * @return OutputReferenceFilter the filter
     */
    public static function inactive(TestCase $test): OutputReferenceFilter {
        $config = (new InMemoryConfig())->mock($test);
        return new OutputReferenceFilter(
            self::mock($test, IRootFolder::class),
            self::mock($test, IShareManager::class),
            $config,
            self::mock($test, IURLGenerator::class),
            new VisibilityGuard($config, self::mock($test, ISystemTagObjectMapper::class)),
            new UnrestrictedFileReferenceScope(),
        );
    }

    /**
     * A filter over the given tree and guard, on a server answering at https://cloud.example.com.
     *
     * @param TestCase $test test creating the doubles
     * @param IRootFolder $root root whose getUserFolder() is the user's tree
     * @param VisibilityGuard $guard exclusion guard over the same tree
     * @param IShareManager $shares share manager the room and public shares resolve through
     * @param FileReferenceScope|null $scope scope step; the empty one when null
     * @param list<string> $trustedDomains trusted_domains of the server
     * @param string $webroot web root the server is installed under
     * @return OutputReferenceFilter the filter
     */
    public static function over(
        TestCase $test,
        IRootFolder $root,
        VisibilityGuard $guard,
        IShareManager $shares,
        ?FileReferenceScope $scope = null,
        array $trustedDomains = ['cloud.example.com'],
        string $webroot = '',
        ?\OCA\Mcp\Access\Guests\GuestRestrictions $guests = null,
    ): OutputReferenceFilter {
        $config = self::mock($test, IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = '') => match ($key) {
            'trusted_domains' => $trustedDomains,
            default => $default,
        });
        $urls = self::mock($test, IURLGenerator::class);
        $urls->method('getWebroot')->willReturn($webroot);
        $urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url) => 'https://cloud.example.com' . $webroot . $url);
        return new OutputReferenceFilter($root, $shares, $config, $urls, $guard, $scope ?? new UnrestrictedFileReferenceScope(), $guests);
    }

    private static function mock(TestCase $test, string $interface): object {
        return (new \ReflectionMethod($test, 'createMock'))->invoke($test, $interface);
    }
}
