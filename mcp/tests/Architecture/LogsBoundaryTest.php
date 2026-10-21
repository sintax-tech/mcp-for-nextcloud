<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class LogsBoundaryTest extends TestCase {
    private static function violations(string $source, bool $inside): array {
        $bad = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
                continue;
            }
            $name = ltrim(str_replace('\\\\', '\\', trim($token->text, "'\"")), '\\');
            if ($inside && strtolower($name) === 'class_alias') {
                $bad[] = 'class_alias';
            }
            if ($inside && (in_array($name, ['Psr\Container\ContainerInterface', 'OCP\Server', 'OC', 'OC\Server', 'OCP\IServerContainer', 'OCP\AppFramework\IAppContainer'], true)
                || str_starts_with($name, 'OCA\Mcp\Tests\\') || str_starts_with($name, 'PHPUnit\\')
                || (str_starts_with($name, 'OCA\Mcp\Modules\\') && $name !== 'OCA\Mcp\Modules\Logs' && !str_starts_with($name, 'OCA\Mcp\Modules\Logs\\')))) {
                $bad[] = $name;
            }
            if (!$inside && str_starts_with($name, 'OCA\Mcp\Modules\Logs\\')) {
                $bad[] = $name;
            }
        }
        return $bad;
    }

    private static function retired(string $source): bool {
        $source = str_replace('\\\\', '\\', $source);
        foreach (['OCA\Mcp\Tools'.'\Logs\\', 'OCA\Mcp\Tests\Unit\Tools'.'\Logs\\', 'lib/Tools'.'/Logs/', 'tests/Unit/Tools'.'/Logs/'] as $old) {
            if (str_contains($source, $old)) { return true; }
        }
        return false;
    }

    public function testScannerDetectsImportsCallsStringsAliasesAndLoaders(): void {
        foreach (['Psr\Container\ContainerInterface', 'OCP\Server', 'OC', 'OCA\Mcp\Tests\Fixture', 'OCA\Mcp\Modules\Contacts\Directory\AccountDirectory'] as $name) {
            foreach (['<?php use '.$name.';', '<?php \\'.$name.'::call();', '<?php $id='.var_export($name, true).';'] as $source) {
                self::assertNotEmpty(self::violations($source, true));
            }
        }
        self::assertNotEmpty(self::violations('<?php class_alias(Current::class, Old::class);', true));
        self::assertNotEmpty(self::violations('<?php \\class_alias(Current::class, Old::class);', true));
        self::assertNotEmpty(self::violations('<?php use OCA\Mcp\Modules\Logs\Reading\LogQuery;', false));
        self::assertTrue(self::retired(json_encode(['class' => 'OCA\Mcp\Tools'.'\Logs\LogsModule'])));
        self::assertTrue(self::retired('<?php require "tests/Unit/Tools'.'/Logs/LogsModuleTest.php";'));
        self::assertSame([], self::violations('<?php namespace OCA\Mcp\Modules\Logs; use OCP\Log\IFileBased;', true));
        self::assertFalse(self::retired('logs_list LogQuery Modules/Logs'));
    }

    public function testFinalOwnersHaveNoRetiredRoutesOrForbiddenDependencies(): void {
        $app = dirname(__DIR__, 2);
        self::assertDirectoryDoesNotExist($app.'/lib/Tools'.'/Logs');
        self::assertDirectoryDoesNotExist($app.'/tests/Unit/Tools'.'/Logs');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app.'/lib', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $source = file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), strlen($app) + 1);
            self::assertFalse(self::retired($source), $relative);
            if ($relative !== 'lib/AppInfo/Application.php') {
                self::assertSame([], self::violations($source, str_starts_with($relative, 'lib/Modules/Logs/')), $relative);
            }
        }
        foreach ([$app.'/tests', $app.'/scripts', $app.'/appinfo', dirname($app).'/.github'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!in_array($file->getExtension(), ['php', 'json', 'xml', 'sh', 'yml', 'yaml'], true)) { continue; }
                self::assertFalse(self::retired(file_get_contents($file->getPathname())), $file->getPathname());
            }
        }
        foreach (['composer.json', 'composer.lock', 'phpunit.xml'] as $file) {
            self::assertFalse(self::retired(file_get_contents($app.'/'.$file)), $file);
        }
    }
}
