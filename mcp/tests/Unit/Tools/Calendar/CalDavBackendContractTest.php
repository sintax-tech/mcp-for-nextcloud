<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every method the adapters call on the Nextcloud DAV backends must exist there. A unit test with a mocked backend
 * cannot notice a method that does not exist in the real class, which is how a call to a missing method reached
 * production in 0.8.1. `nextcloud/ocp` does not ship `OCA\DAV` and the test stubs of it are partial, so the public
 * methods always come from {@see CalDavBackendMethods}, a fixed list taken from the Nextcloud 33.0.2 source.
 */
final class CalDavBackendContractTest extends TestCase {
    private const CALDAV_CLASS = 'OCA\DAV\CalDAV\CalDavBackend';
    private const CARDDAV_CLASS = 'OCA\DAV\CardDAV\CardDavBackend';
    /** Directories under lib/ whose classes talk to the DAV backends. */
    private const SCANNED = ['Tools/Calendar', 'Tools/Tasks', 'Tools/Contacts', 'Service/Calendar'];

    /**
     * Collects `backend()->method(` and `container->get(XxxBackend::class)->method(` calls per backend class.
     *
     * @return array<string, array<string, list<string>>> backend class => method => files that call it
     */
    private static function calls(): array {
        $calls = [];
        $root = dirname(__DIR__, 4) . '/lib';
        foreach (self::SCANNED as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string)file_get_contents($file->getPathname());
                $backend = str_contains($source, 'CardDavBackend') ? self::CARDDAV_CLASS : self::CALDAV_CLASS;
                $found = preg_match_all('/(?:->backend\(\)|->get\((?:CalDavBackend|CardDavBackend)::class\))\s*->\s*(\w+)\s*\(/', $source, $matches);
                foreach ($found ? $matches[1] : [] as $method) {
                    $calls[$backend][$method][] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        return $calls;
    }

    /**
     * @param string $class backend class name
     * @return list<string> public method names
     */
    private static function publicMethods(string $class): array {
        return $class === self::CALDAV_CLASS ? CalDavBackendMethods::CALDAV : CalDavBackendMethods::CARDDAV;
    }

    /**
     * @return array<string, array{string, string, list<string>}> one case per backend method the adapters call
     */
    public static function calledMethods(): array {
        $cases = [];
        foreach (self::calls() as $class => $methods) {
            foreach ($methods as $method => $files) {
                $cases[substr($class, strrpos($class, '\\') + 1) . '::' . $method] = [$class, $method, array_values(array_unique($files))];
            }
        }
        return $cases;
    }

    /**
     * @param string $class backend class the adapters resolve
     * @param string $method method they call on it
     * @param list<string> $files files making the call
     */
    #[DataProvider('calledMethods')]
    public function testEveryBackendMethodTheAdaptersCallExists(string $class, string $method, array $files): void {
        self::assertContains($method, self::publicMethods($class), sprintf('%s::%s() is called from %s but is not a public method of Nextcloud 33.0.2', $class, $method, implode(', ', $files)));
    }

    public function testTheScannerSeesTheKnownAdapterCalls(): void {
        $cases = self::calledMethods();
        foreach (['CalDavBackend::getCalendarsForUser', 'CalDavBackend::calendarQuery', 'CalDavBackend::getCalendarObject', 'CalDavBackend::getMultipleCalendarObjects', 'CardDavBackend::getCards'] as $known) {
            self::assertArrayHasKey($known, $cases, "scanner no longer finds $known");
        }
    }
}
