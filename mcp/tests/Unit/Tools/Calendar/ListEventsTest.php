<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use RuntimeException;

final class ListEventsTest extends CalendarTestCase {
    private function list(array $arguments): array {
        return self::json($this->call('calendar_list_events', $arguments));
    }

    public function testSimpleEventInUtcWithCalendarPath(): void {
        $this->store->addObject(1, 'a.ics', self::ics("UID:a\nSUMMARY:Reunião\nLOCATION:Sala 1\nDTSTART:20260310T130000Z\nDTEND:20260310T140000Z"));
        $this->assertSame([[
            'uid' => 'a', 'summary' => 'Reunião', 'start' => '2026-03-10T13:00:00.000Z', 'end' => '2026-03-10T14:00:00.000Z',
            'location' => 'Sala 1', 'allDay' => false, 'calendar' => self::PERSONAL,
        ]], $this->list(['calendar' => self::PERSONAL]));
    }

    public function testDefaultWindowIsNextSevenDays(): void {
        $this->store->addObject(1, 'in.ics', self::ics("UID:in\nDTSTART:20260316T100000Z\nDTEND:20260316T110000Z"));
        $this->store->addObject(1, 'out.ics', self::ics("UID:out\nDTSTART:20260318T100000Z\nDTEND:20260318T110000Z"));
        $this->assertSame(['in'], array_column($this->list([]), 'uid'));
    }

    public function testAllCalendarsWhenNoneGiven(): void {
        $this->store->addObject(1, 'a.ics', self::ics("UID:a\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z"));
        $this->store->addObject(3, 'b.ics', self::ics("UID:b\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z"));
        $this->assertEqualsCanonicalizing(['a', 'b'], array_column($this->list([]), 'uid'));
    }

    public function testMultiDayAllDayUsesCivilDays(): void {
        $this->store->addObject(1, 'd.ics', self::ics("UID:d\nSUMMARY:Férias\nDTSTART;VALUE=DATE:20260311\nDTEND;VALUE=DATE:20260314"));
        $item = $this->list(['from' => '2026-03-01', 'to' => '2026-03-31'])[0];
        $this->assertSame(['2026-03-11', '2026-03-14', true], [$item['start'], $item['end'], $item['allDay']]);
        $this->assertArrayNotHasKey('timeZone', $item);
    }

    public function testTzidIsConvertedToUtcAndReported(): void {
        $this->store->addObject(1, 't.ics', self::ics("UID:t\nDTSTART;TZID=America/Sao_Paulo:20260311T090000\nDTEND;TZID=America/Sao_Paulo:20260311T100000"));
        $item = $this->list(['from' => '2026-03-11', 'to' => '2026-03-12'])[0];
        $this->assertSame(['2026-03-11T12:00:00.000Z', 'America/Sao_Paulo'], [$item['start'], $item['timeZone']]);
    }

    public function testRecurrenceHonoursExdateOverridesAndCancelledOverride(): void {
        $master = "UID:r\nSUMMARY:Daily\nDTSTART:20260301T100000Z\nDTEND:20260301T110000Z\nRRULE:FREQ=DAILY;COUNT=5\nEXDATE:20260302T100000Z";
        $overrides = "BEGIN:VEVENT\nUID:r\nRECURRENCE-ID:20260303T100000Z\nSUMMARY:Moved\nDTSTART:20260303T150000Z\nDTEND:20260303T160000Z\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nUID:r\nRECURRENCE-ID:20260304T100000Z\nSTATUS:CANCELLED\nDTSTART:20260304T100000Z\nDTEND:20260304T110000Z\nEND:VEVENT";
        $this->store->addObject(1, 'r.ics', self::ics($master, $overrides));
        $items = $this->list(['from' => '2026-03-01', 'to' => '2026-03-10']);
        $this->assertSame(
            [['2026-03-01T10:00:00.000Z', 'Daily'], ['2026-03-03T15:00:00.000Z', 'Moved'], ['2026-03-05T10:00:00.000Z', 'Daily']],
            array_map(static fn (array $i) => [$i['start'], $i['summary']], $items),
        );
    }

    public function testOldDailySeriesIsExpandedInsideTheWindowOnly(): void {
        $this->store->addObject(1, 'o.ics', self::ics("UID:o\nDTSTART:20000101T100000Z\nDTEND:20000101T110000Z\nRRULE:FREQ=DAILY"));
        $this->assertCount(3, $this->list(['from' => '2026-03-10T00:00:00Z', 'to' => '2026-03-13T00:00:00Z']));
    }

    public function testMoreThan500OccurrencesPerSeriesIsAnError(): void {
        $this->store->addObject(1, 'h.ics', self::ics("UID:h\nDTSTART:20260101T000000Z\nDTEND:20260101T001000Z\nRRULE:FREQ=HOURLY"));
        self::assertToolError($this->call('calendar_list_events', ['from' => '2026-01-01', 'to' => '2026-02-01']), 'Limite de 500 ocorrências por série');
    }

    public function testExactly500OccurrencesIsAccepted(): void {
        $this->store->addObject(1, 'h.ics', self::ics("UID:h\nDTSTART:20260101T000000Z\nDTEND:20260101T001000Z\nRRULE:FREQ=HOURLY;COUNT=500"));
        $this->assertCount(500, $this->list(['from' => '2026-01-01', 'to' => '2026-02-01']));
    }

    public function testWindowAbove366DaysIsAnError(): void {
        self::assertToolError($this->call('calendar_list_events', ['from' => '2026-01-01', 'to' => '2027-01-03']), '366 dias');
        $this->assertSame([], $this->list(['from' => '2026-01-01', 'to' => '2027-01-02']));
    }

    public function testMoreThan2000ItemsIsTruncatedAndFlagged(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->store->addObject(1, "s$i.ics", self::ics("UID:s$i\nDTSTART:20260101T000000Z\nDTEND:20260101T000100Z\nRRULE:FREQ=HOURLY;COUNT=450"));
        }
        $result = $this->call('calendar_list_events', ['from' => '2026-01-01', 'to' => '2026-03-01']);
        $this->assertCount(2000, self::json($result));
        $this->assertSame(['truncated' => true, 'limit' => 2000], self::json($result, 1));
    }

    public function testExactly2000ItemsIsNotFlagged(): void {
        for ($i = 0; $i < 4; $i++) {
            $this->store->addObject(1, "s$i.ics", self::ics("UID:s$i\nDTSTART:20260101T000000Z\nDTEND:20260101T000100Z\nRRULE:FREQ=HOURLY;COUNT=500"));
        }
        $result = $this->call('calendar_list_events', ['from' => '2026-01-01', 'to' => '2026-03-01']);
        $this->assertCount(2000, self::json($result));
        $this->assertCount(1, $result['content']);
    }

    public function testPrivateEventsOfOtherOwnersAreHiddenAndConfidentialRedacted(): void {
        $this->store->addObject(3, 'p.ics', self::ics("UID:p\nCLASS:PRIVATE\nSUMMARY:Segredo\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z"));
        $this->store->addObject(3, 'c.ics', self::ics("UID:c\nCLASS:CONFIDENTIAL\nSUMMARY:Médico\nLOCATION:Clínica\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z"));
        $items = $this->list(['calendar' => self::TEAM]);
        $this->assertSame([['c', 'Ocupado', '']], array_map(static fn (array $i) => [$i['uid'], $i['summary'], $i['location']], $items));
    }

    public function testOwnPrivateEventsAreShown(): void {
        $this->store->addObject(1, 'p.ics', self::ics("UID:p\nCLASS:PRIVATE\nSUMMARY:Segredo\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z"));
        $this->assertSame('Segredo', $this->list(['calendar' => self::PERSONAL])[0]['summary']);
    }

    public function testInvalidObjectIsSkipped(): void {
        $this->store->addObject(1, 'bad.ics', 'not an icalendar');
        $this->store->addObject(1, 'a.ics', self::ics("UID:a\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z"));
        $this->assertSame(['a'], array_column($this->list(['calendar' => self::PERSONAL]), 'uid'));
    }

    public function testForeignOrUnknownCalendarIsNotFound(): void {
        foreach (['/remote.php/dav/calendars/bob/team/', '/remote.php/dav/calendars/alice/../bob/team/', '/remote.php/dav/calendars/alice/nope/', '/remote.php/dav/calendars/alice/old/', 'personal'] as $path) {
            self::assertToolError($this->call('calendar_list_events', ['calendar' => $path]), 'não encontrado');
        }
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function invalidDates(): array {
        return [
            'bad format' => [['from' => '10/03/2026']],
            'no offset' => [['from' => '2026-03-10T10:00:00']],
            'bad day' => [['from' => '2026-02-30']],
            'bad hour' => [['from' => '2026-03-10T25:00:00Z']],
            'inverted' => [['from' => '2026-03-10', 'to' => '2026-03-09']],
            'equal' => [['from' => '2026-03-10', 'to' => '2026-03-10']],
        ];
    }

    /** @dataProvider invalidDates */
    public function testInvalidDatesAreInvalidArguments(array $arguments): void {
        $this->expectException(CalendarArgumentException::class);
        $this->call('calendar_list_events', $arguments);
    }

    public function testBackendFailurePropagates(): void {
        $this->store->failure = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->call('calendar_list_events', []);
    }
}
