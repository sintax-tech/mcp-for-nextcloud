<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;
final class NotesBoundaryTest extends TestCase
{

    private static function names(string $source): array
    {
        $names = [];
        foreach (\PhpToken::tokenize($source) as $t) {
            if (in_array($t->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
                $names[] = ltrim(str_replace('\\\\', '\\', trim($t->text, "'\"")), '\\');
            }
        }
        return $names;
    }

    private static function violations(string $source, bool $inside): array
    {
        $bad = [];
        foreach (self::names($source) as $n) {
            if ($inside && (in_array($n, ['Psr\Container\ContainerInterface', 'OCP\Server', 'OC\Server', 'OCP\IServerContainer', 'OCP\AppFramework\IAppContainer'], true) || str_starts_with($n, 'OCA\Mcp\Tests\\') || str_starts_with($n, 'PHPUnit\\') || str_starts_with($n, 'OCA\Mcp\Modules\\') && $n !== 'OCA\Mcp\Modules\Notes' && !str_starts_with($n, 'OCA\Mcp\Modules\Notes\\'))) {
                $bad[] = $n;
            }
            if (!$inside && str_starts_with($n, 'OCA\Mcp\Modules\Notes\\') && !str_starts_with($n, 'OCA\Mcp\Modules\Notes\Contracts\\')) {
                $bad[] = $n;
            }
        }
        return $bad;
    }

    private static function retired(string $source): bool
    {
        $source = str_replace('\\\\', '\\', $source);
        if (str_contains($source, 'OCA\Mcp\Tools' . '\Notes\\') || str_contains($source, 'lib/Tools' . '/Notes/')) {
            return true;
        }
        foreach (['Module', 'Preview', 'PlanRenderer', 'Lock', 'Translation'] as $part) {
            if (str_contains($source, 'Tests\Unit\Tools' . '\Notes' . $part . 'Test') || str_contains($source, 'tests/Unit/Tools' . '/Notes' . $part . 'Test.php')) {
                return true;
            }
        }
        return false;
    }

    public function testScannerRejectsImportsCallsStringsAndOldLoaders(): void
    {
        foreach (['Psr\Container\ContainerInterface', 'OCP\Server', 'OCA\Mcp\Tests\Fixture', 'OCA\Mcp\Modules\Files\Reading\ReadFile'] as $name) {
            foreach (['<?php use ' . $name . ';', '<?php \\' . $name . '::call();', '<?php $id=' . var_export($name, true) . ';'] as $source) {
                self::assertNotEmpty(self::violations($source, true));
            }
        }
        self::assertNotEmpty(self::violations('<?php use OCA\Mcp\Modules\Notes\Storage\NoteLookup;', false));
        self::assertSame([], self::violations('<?php use OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader;', false));
        self::assertTrue(self::retired(json_encode(['class' => 'OCA\Mcp\Tools' . '\Notes\NotesModule'])));
        self::assertTrue(self::retired('tests/Unit/Tools' . '/NotesModuleTest.php'));
        self::assertFalse(self::retired('OCA\Mcp\Mcp\Guide\ToolGuideNotes'));
    }

    public function testFinalProductionOwnersHaveNoLocatorsCyclesOrOldRoutes(): void
    {
        $app = dirname(__DIR__, 2);
        self::assertDirectoryDoesNotExist($app . '/lib/Tools' . '/Notes');
        foreach (['Module', 'Preview', 'PlanRenderer', 'Lock', 'Translation'] as $part) {
            self::assertFileDoesNotExist($app . '/tests/Unit/Tools' . '/Notes' . $part . 'Test.php');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app . '/lib', \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($f->getPathname());
            $relative = substr($f->getPathname(), strlen($app) + 1);
            self::assertFalse(self::retired($source), $relative);
            if ($relative !== 'lib/AppInfo/Application.php') {
                self::assertSame([], self::violations($source, str_contains($relative, 'lib/Modules/Notes/')), $relative);
            }
        }
        foreach (['tests', 'scripts', 'appinfo'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!in_array($f->getExtension(), ['php', 'json', 'xml', 'sh'], true)) {
                    continue;
                }
                self::assertFalse(self::retired(file_get_contents($f->getPathname())), $f->getPathname());
            }
        }
        foreach (['composer.json', 'composer.lock', 'phpunit.xml'] as $file) {
            self::assertFalse(self::retired(file_get_contents($app . '/' . $file)), $file);
        }
    }
}
