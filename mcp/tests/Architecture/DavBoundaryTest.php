<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;
use PHPUnit\Framework\TestCase;
final class DavBoundaryTest extends TestCase {
    private static function names(string $source): array {
        $names = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
                $names[] = ltrim(str_replace('\\\\', '\\', trim($token->text, "'\"")), '\\');
            }
        }
        return $names;
    }
    private static function violations(string $source, string $owner, string $path): array {
        $bad = [];
        foreach (self::names($source) as $name) {
            if (in_array($name, ['Psr\Container\ContainerInterface', 'OCP\Server', 'OC\Server', 'OCP\IServerContainer', 'OCP\AppFramework\IAppContainer'], true)
                || str_starts_with($name, 'OCA\Mcp\Tests\\') || str_starts_with($name, 'PHPUnit\\')) { $bad[] = $name; }
            if (preg_match('~^OCA\\\\Mcp\\\\Modules\\\\([^\\\\]+)~', $name, $match) && $match[1] !== $owner) { $bad[] = $name; }
            if ($owner !== 'Dav' && str_starts_with($name, 'OCA\DAV\\') && !str_contains($path, '/Integration/')) { $bad[] = $name; }
            if (in_array($name, ['OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration', 'OCA\Mcp\Platform\Dav\CalDav\CalendarAclProbe'], true)
                && $owner !== 'Dav' && !str_contains($path, '/Selftest/')) { $bad[] = $name; }
        }
        return array_values(array_unique($bad));
    }
    private static function retired(string $source): array {
        $source = str_replace('\\\\', '\\', $source);
        $bad = [];
        foreach (['Calendar', 'Contacts', 'Tasks', 'Dav'] as $feature) {
            foreach (['OCA\Mcp\Tools\\', 'OCA\Mcp\Tests\Unit\Tools\\', 'lib/Tools/', 'tests/Unit/Tools/'] as $prefix) {
                if (preg_match('~'.preg_quote($prefix.$feature, '~').'(?:[\\\\/]|[\'";]|$)~', $source)) { $bad[] = $prefix.$feature; }
            }
        }
        foreach (['OCA\Mcp\Service\\', 'OCA\Mcp\Tests\Unit\Service\\', 'lib/Service/', 'tests/Unit/Service/'] as $prefix) {
            if (str_contains($source, $prefix.'Calendar')) { $bad[] = $prefix.'Calendar'; }
        }
        return $bad;
    }
    public function testFinalOwnersHaveNoFeatureCyclesServiceLocatorsOrDiagnosticPrivilegeLeaks(): void {
        foreach (['Calendar', 'Contacts', 'Tasks', 'Dav'] as $owner) {
            $root = __DIR__.'/../../lib/'.($owner === 'Dav' ? 'Platform/Dav' : 'Modules/'.$owner);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->getExtension() !== 'php') { continue; }
                self::assertSame([], self::violations(file_get_contents($file->getPathname()), $owner, $file->getPathname()), $file->getPathname());
            }
        }
    }
    public function testScannersDetectImportsCallsStringsAndSupportPathsWithoutPrefixFalsePositives(): void {
        foreach (['OCA\Mcp\Modules\Contacts\Cards\ContactCard', 'OCA\Mcp\Tests\Support\Fixture', 'Psr\Container\ContainerInterface', 'OCP\Server', 'OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration'] as $name) {
            foreach (['<?php use '.$name.';', '<?php \\'.$name.'::run();', '<?php $id = '.var_export($name, true).';'] as $source) {
                self::assertSame([$name], self::violations($source, 'Calendar', '/Tools/CreateEvent.php'));
            }
        }
        $old = 'OCA\Mcp\Tools'.'\Calendar\OldOwner';
        foreach (['<?php use '.$old.';', json_encode(['id'=>$old]), 'lib/Tools'.'/Calendar/OldOwner.php', 'tests/Unit/Service'.'/Calendar/Test.php'] as $source) { self::assertNotEmpty(self::retired($source)); }
        self::assertSame([], self::retired('OCA\Mcp\Mcp\Tools\DavCollectionSchema'));
        self::assertNotEmpty(self::violations('<?php use OCA\Mcp\Modules\Calendar\Events\EventBuilder;', 'Dav', '/CalDav/Writer.php'));
    }
    public function testNoRetiredSourceTestTreeLoaderOrClassStringRemains(): void {
        $root = dirname(__DIR__, 3);
        foreach (['lib/Tools/', 'tests/Unit/Tools/'] as $prefix) {
            foreach (['Calendar', 'Contacts', 'Tasks', 'Dav'] as $feature) { self::assertDirectoryDoesNotExist($root.'/mcp/'.$prefix.$feature); }
        }
        foreach (['lib/Service/', 'tests/Unit/Service/'] as $prefix) { self::assertDirectoryDoesNotExist($root.'/mcp/'.$prefix.'Calendar'); }
        foreach (['Modules/Calendar','Modules/Contacts','Modules/Tasks','Platform/Dav','Mcp/Tools','Mcp/Confirmation'] as $suite) {
            self::assertNotEmpty(glob($root.'/mcp/tests/Unit/'.$suite.'/*.php'), $suite);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root)+1);
            if (!preg_match('~^(?:mcp/(?:lib|tests|appinfo|scripts)/|\.github/|mcp/(?:composer\.(?:json|lock)|phpunit\.xml)$)~', $relative)
                || !in_array($file->getExtension(), ['php','json','xml','sh','py','yml','yaml','lock'], true)) { continue; }
            self::assertSame([], self::retired(file_get_contents($file->getPathname())), $relative);
        }
    }
}
