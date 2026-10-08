<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use InvalidArgumentException;
use OCA\Mcp\Tools\Logs\LogFilter;
use OCA\Mcp\Tools\Logs\LogTime;
use PHPUnit\Framework\TestCase;

final class LogFilterTest extends TestCase {
    private static function entry(array $fields = []): \stdClass {
        return (object)($fields + [
            'reqId' => 'r1', 'level' => 3, 'time' => '2026-10-07T21:14:03+00:00', 'user' => 'alice', 'app' => 'webdav',
            'message' => 'File name contains trailing spaces', 'url' => '/remote.php/dav/files/alice/x',
        ]);
    }

    private static function filter(array $arguments, ?LogTime $time = null): LogFilter {
        return LogFilter::fromArguments($arguments, $time ?? new LogTime(\DateTimeInterface::ATOM, 'UTC'));
    }

    public function testWithoutArgumentsEveryEntryMatches(): void {
        $filter = self::filter([]);
        $this->assertTrue($filter->isEmpty());
        $this->assertSame(LogFilter::MATCH, $filter->test(self::entry()));
        $this->assertSame([], $filter->describe());
    }

    public function testLevelAppUserAndRequestAreExact(): void {
        $filter = self::filter(['min_level' => 3, 'app' => 'webdav', 'user' => 'alice', 'req_id' => 'r1']);
        $this->assertFalse($filter->isEmpty());
        $this->assertSame(LogFilter::MATCH, $filter->test(self::entry()));
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['level' => 2])));
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['app' => 'files'])));
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['user' => 'Alice'])));
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['reqId' => 'r2'])));
        $this->assertSame(['min_level' => 3, 'app' => 'webdav', 'user' => 'alice', 'req_id' => 'r1'], $filter->describe());
    }

    /** Plain text, case-insensitive, never a pattern: a regex metacharacter is just a character. */
    public function testContainsIsACaseInsensitiveSubstringOfTheMessage(): void {
        $this->assertSame(LogFilter::MATCH, self::filter(['contains' => 'TRAILING'])->test(self::entry()));
        $this->assertSame(LogFilter::SKIP, self::filter(['contains' => 'trail.*spaces'])->test(self::entry()));
        $this->assertSame(LogFilter::MATCH, self::filter(['contains' => 'a.b'])->test(self::entry(['message' => 'Doc a.b failed'])));
        $this->assertSame(LogFilter::MATCH, self::filter(['contains' => 'ação'])->test(self::entry(['message' => 'Falha na AÇÃO'])));
    }

    public function testTheDateWindowComparesInstantsAcrossTimezones(): void {
        $filter = self::filter(['since' => '2026-10-07T18:00:00-03:00', 'until' => '2026-10-07T18:30:00-03:00']);
        $this->assertSame(LogFilter::MATCH, $filter->test(self::entry(['time' => '2026-10-07T21:14:03+00:00'])));
        $this->assertSame(LogFilter::MATCH, $filter->test(self::entry(['time' => '2026-10-07T21:00:00+00:00'])));
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['time' => '2026-10-07T21:31:00+00:00'])));
        // Before `since`, by a second or by a day, an entry is skipped: the order of the file never ends a search.
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['time' => '2026-10-07T20:59:59+00:00'])));
        $this->assertSame(LogFilter::SKIP, $filter->test(self::entry(['time' => '2026-10-06T20:59:59+00:00'])));
        $this->assertFalse(defined(LogFilter::class . '::BEFORE_WINDOW'));
    }

    /** A time the configured format cannot read is left out of a date filter and counted, never guessed. */
    public function testAnUnreadableTimeIsUnparsedOnlyUnderADateFilter(): void {
        $odd = self::entry(['time' => '07/10/2026 21:14']);
        $this->assertSame(LogFilter::UNPARSED, self::filter(['since' => '2026-10-01T00:00:00Z'])->test($odd));
        $this->assertSame(LogFilter::MATCH, self::filter(['app' => 'webdav'])->test($odd));
    }

    public function testTheTimeFollowsTheConfiguredFormatAndTimezone(): void {
        $time = new LogTime('d.m.Y H:i:s', 'America/Sao_Paulo');
        $this->assertSame('2026-10-07T21:14:03+00:00', $time->parse('07.10.2026 18:14:03')?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        $this->assertNull($time->parse('2026-10-07T21:14:03+00:00'));
        $this->assertNull($time->parse(''));
        $atom = new LogTime(DATE_ATOM, 'not/a-zone');
        $this->assertSame('2026-10-07T21:14:03+00:00', $atom->parse('2026-10-07T21:14:03+00:00')?->format(DATE_ATOM));
    }

    public function testInvalidArgumentsAreRefused(): void {
        foreach ([
            ['since' => 'yesterday-ish'],
            ['until' => '2026-13-40'],
            ['since' => '2026-10-08T00:00:00Z', 'until' => '2026-10-07T00:00:00Z'],
            ['min_level' => 5],
            ['min_level' => -1],
            ['contains' => ''],
        ] as $arguments) {
            try {
                self::filter($arguments);
                $this->fail(json_encode($arguments) . ' must be refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDescribeKeepsTheDatesAsTheCallerWroteThem(): void {
        $this->assertSame(['since' => '2026-10-07T18:00:00-03:00', 'contains' => 'x'], self::filter(['contains' => 'x', 'since' => '2026-10-07T18:00:00-03:00'])->describe());
    }
}
