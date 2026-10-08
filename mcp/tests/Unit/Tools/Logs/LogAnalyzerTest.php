<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCA\Mcp\Tools\Logs\LogAnalyzer;
use OCA\Mcp\Tools\Logs\LogTime;
use PHPUnit\Framework\TestCase;

final class LogAnalyzerTest extends TestCase {
    /** @param list<array<string, mixed>> $rows newest first, as the scanner hands them over */
    private static function analyze(array $rows): array {
        $entries = array_map(static fn (array $row): \stdClass => json_decode(json_encode($row + [
            'level' => 3, 'app' => 'webdav', 'user' => 'alice', 'url' => '/remote.php/dav/files/alice/a.txt', 'userAgent' => 'mirall/3.14',
            'message' => 'x', 'time' => '2026-10-07T18:14:03-03:00',
        ])), $rows);
        return (new LogAnalyzer(new LogTime(DATE_ATOM, 'UTC')))->analyze($entries);
    }

    /** Numbers, hashes, paths and quoted text are what changes between two occurrences of the same problem. */
    public function testSignaturesFoldWhatVariesBetweenOccurrences(): void {
        $this->assertSame('File # is locked by # (#)', LogAnalyzer::signature('File "RH/Folha 2026.xlsx" is locked by \'bob\' (42)'));
        $this->assertSame('Duplicate entry # for key #', LogAnalyzer::signature("Duplicate entry '1234-ab' for key 'gf_storage_path_hash'"));
        $this->assertSame('Could not open # at #', LogAnalyzer::signature('Could not open /var/www/data/x.txt at 3f9a1c2e7b8d4e6f'));
        $this->assertSame('Login failed from #.#.x.x', LogAnalyzer::signature('Login failed from 198.51.x.x'));
        $this->assertSame('Request # took # ms', LogAnalyzer::signature('Request 550e8400-e29b-41d4-a716-446655440000 took 1500 ms'));
        $this->assertSame("can't read #", LogAnalyzer::signature("can't read 'a b'"));
        $this->assertSame(200, mb_strlen(LogAnalyzer::signature(str_repeat('word ', 100))));
    }

    public function testTotalsByLevelAppAndUser(): void {
        $result = self::analyze([
            ['level' => 4], ['level' => 3, 'app' => 'core', 'user' => 'bob'], ['level' => 3], ['level' => 2, 'user' => '--'], ['level' => 0],
        ]);
        $this->assertSame(5, $result['matched']);
        $this->assertSame(['fatal' => 1, 'error' => 2, 'warning' => 1, 'debug' => 1], $result['byLevel']);
        $this->assertSame([['app' => 'webdav', 'count' => 4], ['app' => 'core', 'count' => 1]], $result['byApp']);
        $this->assertSame([['user' => 'alice', 'count' => 3], ['user' => '--', 'count' => 1], ['user' => 'bob', 'count' => 1]], $result['byUser']);
    }

    public function testTheTopSignaturesCarryCountFirstLastAndAnExample(): void {
        $result = self::analyze([
            ['message' => 'Timeout after 30 s from 10.0.0.1', 'time' => '2026-10-07T20:00:00-03:00'],
            ['message' => 'Other problem'],
            ['message' => 'Timeout after 31 s from 10.0.0.2', 'time' => '2026-10-07T19:00:00-03:00'],
            ['message' => 'Timeout after 12 s', 'time' => '2026-10-07T17:00:00-03:00'],
        ]);
        $top = $result['signatures'][0];
        $this->assertSame('Timeout after # s from #.#.x.x', $top['signature']);
        $this->assertSame(2, $top['count']);
        $this->assertSame('2026-10-07T19:00:00-03:00', $top['first']);
        $this->assertSame('2026-10-07T20:00:00-03:00', $top['last']);
        $this->assertSame('Timeout after 30 s from 10.0.x.x', $top['example']);
        $this->assertSame(['error', 'webdav'], [$top['level'], $top['app']]);
        $this->assertCount(3, $result['signatures']);
    }

    public function testAtMostTwentySignatures(): void {
        $rows = [];
        foreach (range('a', 'y') as $i => $letter) {
            for ($n = 0; $n <= $i; $n++) {
                $rows[] = ['message' => 'problem ' . $letter];
            }
        }
        $result = self::analyze($rows);
        $this->assertCount(LogAnalyzer::TOP_SIGNATURES, $result['signatures']);
        $this->assertSame('problem y', $result['signatures'][0]['signature']);
        $this->assertSame(25, $result['distinctSignatures']);
    }

    /** A WebDAV path names a file of somebody: the endpoint is kept, the rest folded, and the query never counts. */
    public function testUrlPathsAreGroupedByEndpoint(): void {
        $result = self::analyze([
            ['url' => '/remote.php/dav/files/alice/RH/Salarios 2026.xlsx'],
            ['url' => '/remote.php/dav/files/alice/Fotos/1.png?x=1'],
            ['url' => '/remote.php/dav/files/bob/doc.pdf'],
            ['url' => '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json'],
            ['url' => '/index.php/apps/files/api/v1/thumbnail/256/256/a.jpg'],
            ['url' => '--'],
        ]);
        $this->assertSame([
            ['path' => '/remote.php/dav/files/alice/…', 'count' => 2],
            ['path' => '--', 'count' => 1],
            ['path' => '/index.php/apps/files/api/v#/thumbnail/#/#/a.jpg', 'count' => 1],
            ['path' => '/ocs/v#.php/apps/notifications/api/v#/notifications', 'count' => 1],
            ['path' => '/remote.php/dav/files/bob/…', 'count' => 1],
        ], $result['topUrls']);
    }

    public function testUserAgentsAndTheHourlyHistogram(): void {
        $result = self::analyze([
            ['time' => '2026-10-07T18:59:59-03:00', 'userAgent' => 'Mozilla/5.0 (Macintosh) mirall/3.14.1'],
            ['time' => '2026-10-07T18:00:00-03:00', 'userAgent' => 'Mozilla/5.0 (Macintosh) mirall/3.14.1'],
            ['time' => '2026-10-07T17:30:00-03:00'],
            ['time' => 'garbled'],
        ]);
        $this->assertSame([['userAgent' => 'Mozilla/5.0 (Macintosh) mirall/3.14.1', 'count' => 2], ['userAgent' => 'mirall/3.14', 'count' => 2]], $result['topUserAgents']);
        $this->assertSame([['hour' => '2026-10-07T17:00-03:00', 'count' => 1], ['hour' => '2026-10-07T18:00-03:00', 'count' => 2]], $result['hourly']);
        $this->assertSame(1, $result['hourlyUnparsed']);
    }

    public function testAnEmptyWindowGivesEmptyTotals(): void {
        $result = self::analyze([]);
        $this->assertSame(0, $result['matched']);
        $this->assertSame([], $result['byLevel']);
        $this->assertSame([], $result['signatures']);
        $this->assertSame([], $result['hourly']);
    }
}
