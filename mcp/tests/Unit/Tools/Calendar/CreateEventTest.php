<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use RuntimeException;
use Sabre\VObject\Reader;

final class CreateEventTest extends CalendarTestCase {
    public function testCreatesTimedEventWithTimeZone(): void {
        $item = self::json($this->call('calendar_create_event', [
            'calendar' => self::PERSONAL, 'summary' => 'Consulta', 'start' => '2026-03-12T09:00:00-03:00', 'end' => '2026-03-12T10:00:00-03:00',
            'timeZone' => 'America/Sao_Paulo', 'location' => 'Centro', 'description' => 'Levar exames',
        ]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $item['uid']);
        $this->assertSame(['Consulta', '2026-03-12T12:00:00.000Z', '2026-03-12T13:00:00.000Z', 'America/Sao_Paulo', self::PERSONAL],
            [$item['summary'], $item['start'], $item['end'], $item['timeZone'], $item['calendar']]);
        [$method, [$calendarId, $uri, $data]] = $this->store->writes[0];
        $this->assertSame(['create', 1, $item['uid'] . '.ics'], [$method, $calendarId, $uri]);
        $this->assertSame($this->store->objects[1][$uri]['etag'], $item['etag']);
        $event = Reader::read($data)->VEVENT;
        $this->assertSame('America/Sao_Paulo', (string)$event->DTSTART['TZID']);
        $this->assertSame('20260312T090000', $event->DTSTART->getValue());
        $this->assertSame(['Centro', 'Levar exames', '0'], [(string)$event->LOCATION, (string)$event->DESCRIPTION, (string)$event->SEQUENCE]);
        $this->assertFalse(isset($event->RRULE));
    }

    public function testCreatesAllDayEventInSharedWritableCalendar(): void {
        $item = self::json($this->call('calendar_create_event', ['calendar' => self::TEAM, 'summary' => 'Feriado', 'start' => '2026-04-21', 'end' => '2026-04-22', 'allDay' => true]));
        $this->assertSame(['2026-04-21', '2026-04-22', true], [$item['start'], $item['end'], $item['allDay']]);
        $event = Reader::read($this->store->writes[0][1][2])->VEVENT;
        $this->assertSame(['DATE', '20260421'], [(string)$event->DTSTART['VALUE'], $event->DTSTART->getValue()]);
    }

    public function testReadOnlyBirthdayAndForeignCalendarsAreRefused(): void {
        $args = ['summary' => 'x', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z'];
        self::assertToolError($this->call('calendar_create_event', ['calendar' => self::READONLY] + $args), 'Sem permissão');
        self::assertToolError($this->call('calendar_create_event', ['calendar' => self::BIRTHDAYS] + $args), 'Sem permissão');
        self::assertToolError($this->call('calendar_create_event', ['calendar' => '/remote.php/dav/calendars/bob/team/'] + $args), 'não encontrado');
        $this->assertNoWrites();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidArguments(): array {
        return [
            'end before start' => [['start' => '2026-03-12T10:00:00Z', 'end' => '2026-03-12T09:00:00Z']],
            'bad date' => [['start' => 'amanhã', 'end' => '2026-03-12T09:00:00Z']],
            'all-day with time' => [['start' => '2026-03-12T10:00:00Z', 'end' => '2026-03-13T10:00:00Z', 'allDay' => true]],
            'unknown zone' => [['start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z', 'timeZone' => 'Mars/Olympus']],
        ];
    }

    /** @dataProvider invalidArguments */
    public function testInvalidArgumentsWriteNothing(array $arguments): void {
        try {
            $this->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'x'] + $arguments);
            $this->fail('expected CalendarArgumentException');
        } catch (CalendarArgumentException) {
            $this->assertNoWrites();
        }
    }

    public function testBackendFailurePropagates(): void {
        $this->store->failure = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'x', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z']);
    }
}
