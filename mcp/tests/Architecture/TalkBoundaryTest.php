<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class TalkBoundaryTest extends TestCase
{
    private static function names(string $source): array
    {
        $names = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
                $names[] = ltrim(str_replace('\\\\', '\\', trim($token->text, "'\"")), '\\');
            }
        }
        return $names;
    }

    private static function violations(string $source, bool $integration): array
    {
        $bad = [];
        foreach (self::names($source) as $name) {
            if (
                in_array(
                    $name,
                    [
                        'Psr\Container\ContainerInterface',
                        'OCP\Server',
                        'OC\Server',
                        'OCP\IServerContainer',
                        'OCP\AppFramework\IAppContainer',
                    ],
                    true,
                )
            ) {
                $bad[] = $name;
            }
            if (str_starts_with($name, 'OCA\Talk\\') && !$integration) {
                $bad[] = $name;
            }
            if (str_starts_with($name, 'OCA\Mcp\Tests\\') || str_starts_with($name, 'PHPUnit\\')) {
                $bad[] = $name;
            }
            if (
                preg_match('~^OCA\\\\Mcp\\\\(?:Tools|Modules)\\\\(?:Calendar|Deck)(?:\\\\|$)~', $name) &&
                !in_array(
                    $name,
                    [
                        'OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader',
                        'OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader',
                        'OCA\Mcp\Modules\Calendar\Contracts\EventReferenceNotFound',
                    ],
                    true,
                )
            ) {
                $bad[] = $name;
            }
        }
        return array_values(array_unique($bad));
    }

    public function testTalkHasNarrowDependenciesAndIsIndependentOfTests(): void
    {
        foreach (
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib/Modules/Talk'))
            as $file
        ) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            self::assertSame(
                [],
                self::violations(
                    file_get_contents($file->getPathname()),
                    str_contains($file->getPathname(), '/Integration/'),
                ),
                $file->getPathname(),
            );
        }
    }

    public function testBoundaryScannerChecksImportsAndSerializedDependencies(): void
    {
        foreach (
            [
                'OCA\Mcp\Modules\Calendar\Events\EventRepository',
                'OCA\Mcp\Modules\Deck\Reading\CardReader',
                'OCA\Talk\Manager',
                'Psr\Container\ContainerInterface',
                'OCA\Mcp\Tests\Support\Fixture',
            ]
            as $name
        ) {
            self::assertSame([$name], self::violations('<?php use ' . $name . ';', false));
            self::assertSame([$name], self::violations('<?php $x = ' . var_export($name, true) . ';', false));
        }
    }

    public function testCalendarReferenceImplementationIsTheOnlyCalendarInternalBoundary(): void
    {
        foreach (['Modules/Calendar'] as $directory) {
            foreach (
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib/' . $directory))
                as $file
            ) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (self::names(file_get_contents($file->getPathname())) as $name) {
                    self::assertFalse(str_starts_with($name, 'OCA\Mcp\Modules\Talk\\'), $file->getPathname());

                }
            }
        }
    }
    private static function retired(string $source): array
    {
        $source = str_replace('\\\\', '\\', $source);
        $names = [
            'OCA\Mcp\Tools' . '\Talk',
            'OCA\Mcp\Tests\Unit\Tools' . '\Talk',
            'lib/Tools' . '/Talk',
            'tests/Unit/Tools' . '/Talk',
        ];
        return array_values(array_filter($names, static fn($name) => str_contains($source, $name)));
    }
    public function testRetiredReferenceAuditCoversServiceIdsAndAutoloadPaths(): void
    {
        $name = 'OCA\Mcp\Tools' . '\Talk\OldOwner';
        self::assertNotEmpty(self::retired('<?php use ' . $name . ';'));
        self::assertNotEmpty(self::retired('$container->get(' . var_export($name, true) . ');'));
        self::assertNotEmpty(self::retired(json_encode(['class' => $name], JSON_THROW_ON_ERROR)));
        self::assertNotEmpty(self::retired('lib/Tools' . '/Talk/OldOwner.php'));
    }
    public function testNoRetiredExecutableOrSupportReferencesRemain(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertDirectoryDoesNotExist($root . '/mcp/lib/Tools' . '/Talk');
        self::assertDirectoryDoesNotExist($root . '/mcp/tests/Unit/Tools' . '/Talk');
        foreach (
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS))
            as $f
        ) {
            $relative = substr($f->getPathname(), strlen($root) + 1);
            if (
                !preg_match(
                    '~^(?:mcp/(?:lib|tests|appinfo|scripts)/|scripts/|\.github/|mcp/(?:composer\.(?:json|lock)|phpunit\.xml)$)~',
                    $relative,
                ) ||
                !in_array($f->getExtension(), ['php', 'json', 'xml', 'sh', 'py', 'yml', 'yaml', 'lock'], true)
            ) {
                continue;
            }
            self::assertSame([], self::retired(file_get_contents($f->getPathname())), $relative);
        }
    }
}
