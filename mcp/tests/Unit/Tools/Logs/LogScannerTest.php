<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCA\Mcp\Tools\Logs\LogFilter;
use OCA\Mcp\Tools\Logs\LogReader;
use OCA\Mcp\Tools\Logs\LogScanner;
use OCA\Mcp\Tools\Logs\LogTime;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Log\ILogFactory;
use OCP\Log\IWriter;
use PHPUnit\Framework\TestCase;

/**
 * Every call reads at most LogScanner::SCAN_LIMIT entries from the end of the current log file, and says how far it
 * got: scanned, truncated, oldestScanned and nextOffset, so the next call goes on exactly where this one stopped.
 */
final class LogScannerTest extends TestCase {
    private function scanner(IWriter $writer): LogScanner {
        $factory = $this->createMock(ILogFactory::class);
        $factory->method('get')->with('file')->willReturn($writer);
        return new LogScanner(new LogReader($factory));
    }

    private static function filter(array $arguments = []): LogFilter {
        return LogFilter::fromArguments($arguments, new LogTime(DATE_ATOM, 'UTC'));
    }

    public function testWithoutFiltersOnlyThePageIsRead(): void {
        $log = new FakeFileLog(FakeFileLog::lines(120, static fn (int $i): array => []));
        $window = $this->scanner($log)->scan(self::filter(), 0, 50);

        $this->assertSame([[50, 0]], $log->calls);
        $this->assertSame(['entry 119', 'entry 70'], [$window['entries'][0]->message, $window['entries'][49]->message]);
        $this->assertSame(50, $window['scanned']);
        $this->assertSame(50, $window['nextOffset']);
        $this->assertFalse($window['truncated']);
        $this->assertSame(gmdate(DATE_ATOM, 1791400000 + 70 * 60), $window['oldestScanned']);

        $next = $this->scanner($log)->scan(self::filter(), 100, 50);
        $this->assertCount(20, $next['entries']);
        $this->assertNull($next['nextOffset'], 'the file ended');
    }

    /** A filter reads the cap at most once and the next call starts right after the last entry returned. */
    public function testAFilteredPageGoesOnAfterItsLastEntry(): void {
        $log = new FakeFileLog(FakeFileLog::lines(300, static fn (int $i): array => $i % 10 === 0 ? ['level' => 3] : []));
        $scanner = $this->scanner($log);

        $first = $scanner->scan(self::filter(['min_level' => 3]), 0, 5);
        $this->assertSame([[LogScanner::SCAN_LIMIT, 0]], $log->calls, 'one read of the window, then the page is cut');
        $this->assertSame(['entry 290', 'entry 280', 'entry 270', 'entry 260', 'entry 250'], array_column($first['entries'], 'message'));
        $this->assertSame(50, $first['scanned']);
        $this->assertSame(50, $first['nextOffset']);
        $this->assertFalse($first['truncated']);

        $second = $scanner->scan(self::filter(['min_level' => 3]), 50, 5);
        $this->assertSame('entry 240', $second['entries'][0]->message);
    }

    public function testTheCapStopsTheSearchAndSaysWhereToGoOn(): void {
        $count = LogScanner::SCAN_LIMIT + 700;
        $log = new FakeFileLog(FakeFileLog::lines($count, static fn (int $i): array => $i === 3 ? ['app' => 'workflow_ocr'] : []));
        $scanner = $this->scanner($log);

        $window = $scanner->scan(self::filter(['app' => 'workflow_ocr']), 0, 50);
        $this->assertSame([[LogScanner::SCAN_LIMIT, 0]], $log->calls);
        $this->assertSame([], $window['entries']);
        $this->assertSame(LogScanner::SCAN_LIMIT, $window['scanned']);
        $this->assertTrue($window['truncated']);
        $this->assertSame(LogScanner::SCAN_LIMIT, $window['nextOffset']);

        $rest = $scanner->scan(self::filter(['app' => 'workflow_ocr']), $window['nextOffset'], 50);
        $this->assertSame(['entry 3'], array_column($rest['entries'], 'message'));
        $this->assertSame(700, $rest['scanned']);
        $this->assertFalse($rest['truncated']);
        $this->assertNull($rest['nextOffset']);
    }

    /**
     * An entry before `since` is skipped and the window goes on: the order of the file is never trusted to end the
     * search, and the answer never claims that nothing older exists while the budget lasts.
     */
    public function testEntriesBeforeSinceAreSkippedAndTheWindowGoesOn(): void {
        $log = new FakeFileLog(FakeFileLog::lines(LogScanner::SCAN_LIMIT + 100, static fn (int $i): array => []));
        $since = gmdate(DATE_ATOM, 1791400000 + (LogScanner::SCAN_LIMIT + 90) * 60);
        $window = $this->scanner($log)->scan(self::filter(['since' => $since]), 0, 50);

        $this->assertCount(10, $window['entries']);
        $this->assertSame(LogScanner::SCAN_LIMIT, $window['scanned']);
        $this->assertTrue($window['truncated']);
        $this->assertSame(LogScanner::SCAN_LIMIT, $window['nextOffset']);

        $small = new FakeFileLog(FakeFileLog::lines(100, static fn (int $i): array => []));
        $all = $this->scanner($small)->scan(self::filter(['since' => gmdate(DATE_ATOM, 1791400000 + 90 * 60)]), 0, 50);
        $this->assertSame([10, 100, null], [count($all['entries']), $all['scanned'], $all['nextOffset']], 'only the end of the file ends it');
    }

    /** Entries written out of order, by minutes or by hours, are all found: none hides the ones before it. */
    public function testEntriesOutOfOrderAreNeverLost(): void {
        $at = static fn (int $minutes): string => gmdate(DATE_ATOM, 1791400000 + $minutes * 60);
        $order = [100, 0, 101, 102, 9, 103, 104, 2, 105];
        $log = new FakeFileLog(FakeFileLog::lines(count($order), static fn (int $i): array => ['time' => $at($order[$i]), 'message' => 'm' . $order[$i]]));

        $window = $this->scanner($log)->scan(self::filter(['since' => $at(100)]), 0, 50);

        $this->assertSame(['m105', 'm104', 'm103', 'm102', 'm101', 'm100'], array_column($window['entries'], 'message'));
        $this->assertSame(9, $window['scanned']);
    }

    /** A line the core could not decode comes back as null: counted, never shown, never fatal. */
    public function testMalformedLinesAndUnreadableTimesAreCounted(): void {
        $lines = FakeFileLog::lines(6, static fn (int $i): array => $i === 1 ? ['time' => '07/10/2026'] : []);
        array_splice($lines, 3, 0, ['{"level":3,"message":"cut in the mid', 'not json at all', '{"message":"no level"}']);
        $log = new FakeFileLog($lines);

        $plain = $this->scanner($log)->scan(self::filter(), 0, 50);
        $this->assertCount(6, $plain['entries']);
        $this->assertSame(3, $plain['malformed']);
        $this->assertSame(0, $plain['unparsed']);
        $this->assertSame(9, $plain['scanned']);

        $dated = $this->scanner($log)->scan(self::filter(['until' => '2030-01-01T00:00:00Z']), 0, 50);
        $this->assertCount(5, $dated['entries']);
        $this->assertSame(1, $dated['unparsed']);
    }

    public function testWithoutALimitTheWholeWindowIsCollected(): void {
        $log = new FakeFileLog(FakeFileLog::lines(30, static fn (int $i): array => []));
        $window = $this->scanner($log)->scan(self::filter(), 0, null);
        $this->assertSame([[LogScanner::SCAN_LIMIT, 0]], $log->calls);
        $this->assertCount(30, $window['entries']);
        $this->assertNull($window['nextOffset']);
    }

    /** syslog, errorlog and systemd writers keep no file to read back. */
    public function testAWriterWithoutAFileIsARefusal(): void {
        $this->expectException(ToolFailure::class);
        $this->scanner($this->createMock(IWriter::class))->scan(self::filter(), 0, 10);
    }

    public function testAFailureOfTheCoreReaderIsARefusalWithoutDetails(): void {
        $log = new class([]) extends \ArrayObject implements IWriter, \OCP\Log\IFileBased {
            public function write(string $app, $message, int $level) {
            }
            public function getLogFilePath(): string {
                return '/secret/path/nextcloud.log';
            }
            public function getEntries(int $limit = 50, int $offset = 0): array {
                throw new \RuntimeException('fopen(/secret/path/nextcloud.log): failed');
            }
        };
        try {
            $this->scanner($log)->scan(self::filter(), 0, 10);
            $this->fail('the failure must become a refusal');
        } catch (ToolFailure $e) {
            $this->assertStringNotContainsString('/secret', $e->getMessage());
        }
    }

    /**
     * No call reads deeper than READ_BUDGET entries from the end, the core offering no budget of its own: near it the
     * window shrinks, and once it is reached there is no further offset to give.
     */
    public function testTheReadBudgetEndsTheSearchAndOffersNoFurtherOffset(): void {
        $this->assertSame(20000, LogScanner::READ_BUDGET);
        $this->assertSame(LogScanner::READ_BUDGET, LogScanner::MAX_OFFSET);
        $log = new FakeFileLog(FakeFileLog::lines(LogScanner::READ_BUDGET + 100, static fn (int $i): array => []));

        $last = $this->scanner($log)->scan(self::filter(['app' => 'nothing']), LogScanner::READ_BUDGET - 2000, 50);
        $this->assertSame(2000, $last['scanned']);
        $this->assertTrue($last['truncated']);
        $this->assertTrue($last['budgetReached']);
        $this->assertNull($last['nextOffset']);

        $early = $this->scanner($log)->scan(self::filter(['app' => 'nothing']), 0, 50);
        $this->assertFalse($early['budgetReached']);
        $this->assertSame(LogScanner::SCAN_LIMIT, $early['nextOffset']);

        $log->calls = [];
        $none = $this->scanner($log)->scan(self::filter(), LogScanner::READ_BUDGET, 50);
        $this->assertSame([[0, 0], 0, true, null], [$log->calls[0] ?? [0, 0], $none['scanned'], $none['budgetReached'], $none['nextOffset']]);
    }

    public function testAnOffsetPastTheBudgetIsRefusedBeforeReading(): void {
        $log = new FakeFileLog([]);
        try {
            $this->scanner($log)->scan(self::filter(), LogScanner::MAX_OFFSET + 1, 10);
            $this->fail('an offset past the budget must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('20000', $e->getMessage());
        }
        $this->assertSame([], $log->calls);
    }

    /**
     * One getEntries per answer, whatever the filters: each call reopens the file from its end, so a second read
     * would cost the walk again and, with the log growing in between, return an entry twice.
     */
    public function testEveryAnswerReadsTheLogOnceAndNeverRepeatsAnEntry(): void {
        // Sparse matches over a log larger than one block of the old reader, which read 500 entries and then the next.
        $log = new FakeFileLog(FakeFileLog::lines(3000, static fn (int $i): array => ['level' => $i % 100 === 0 ? 3 : 2]));
        $log->growth = 40;
        $scanner = $this->scanner($log);
        foreach ([
            [['min_level' => 3], 50], [['since' => gmdate(DATE_ATOM, 1791400000 + 200 * 60)], 50], [['app' => 'core'], null],
            [['since' => gmdate(DATE_ATOM, 1791400000)], null], [[], 20],
        ] as [$arguments, $limit]) {
            $log->calls = [];
            $window = $scanner->scan(self::filter($arguments), 0, $limit);
            $this->assertCount(1, $log->calls, json_encode($arguments));
            $ids = array_column($window['entries'], 'reqId');
            $this->assertSame(count($ids), count(array_unique($ids)), json_encode($arguments));
        }
    }

    /** At a high offset the window shrinks to the budget, in the same single read. */
    public function testAHighOffsetReadsOnlyWhatIsLeftOfTheBudget(): void {
        $log = new FakeFileLog(FakeFileLog::lines(10, static fn (int $i): array => []));
        $this->scanner($log)->scan(self::filter(['app' => 'x']), LogScanner::READ_BUDGET - 2000, 50);
        $this->assertSame([[2000, LogScanner::READ_BUDGET - 2000]], $log->calls);
    }
}
