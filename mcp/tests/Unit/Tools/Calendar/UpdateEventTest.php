<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use RuntimeException;
use Sabre\VObject\Reader;

final class UpdateEventTest extends CalendarTestCase {
    private const EVENT = "UID:e\nSUMMARY:Antigo\nLOCATION:Sala\nSEQUENCE:2\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\n"
        . "ORGANIZER:mailto:alice@example.com\nATTENDEE;CN=Bob:mailto:bob@example.com\nX-CUSTOM:keep";
    private const ALARM = "BEGIN:VALARM\nACTION:DISPLAY\nTRIGGER:-PT15M\nDESCRIPTION:Lembrete\nEND:VALARM";

    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'e.ics', self::ics(self::EVENT . "\n" . self::ALARM));
    }

    private function written(): \Sabre\VObject\Component\VEvent {
        return Reader::read($this->store->writes[0][1][2])->VEVENT;
    }

    public function testChangesTextAndPreservesEverythingElse(): void {
        $item = self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'summary' => 'Novo', 'location' => '']));
        $this->assertSame(['Novo', ''], [$item['summary'], $item['location']]);
        $event = $this->written();
        $this->assertSame(['Novo', '3', 'mailto:bob@example.com', 'mailto:alice@example.com', 'keep', '-PT15M'], [
            (string)$event->SUMMARY, (string)$event->SEQUENCE, (string)$event->ATTENDEE, (string)$event->ORGANIZER,
            (string)$event->{'X-CUSTOM'}, (string)$event->VALARM->TRIGGER,
        ]);
        $this->assertFalse(isset($event->LOCATION));
        $this->assertSame('20260310T120000Z', (string)$event->DTSTAMP);
        $this->assertSame(['update', 1, 'e.ics'], [$this->store->writes[0][0], $this->store->writes[0][1][0], $this->store->writes[0][1][1]]);
        $this->assertSame($this->store->objects[1]['e.ics']['etag'], $item['etag']);
    }

    public function testChangesTimingKeepingMissingBound(): void {
        $item = self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'start' => '2026-03-12T08:00:00Z']));
        $this->assertSame(['2026-03-12T08:00:00.000Z', '2026-03-12T10:00:00.000Z'], [$item['start'], $item['end']]);
    }

    public function testSwitchesToAllDayWhenBothDatesAreGiven(): void {
        $item = self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'allDay' => true, 'start' => '2026-03-12', 'end' => '2026-03-13']));
        $this->assertSame([true, '2026-03-12'], [$item['allDay'], $item['start']]);
        $this->assertSame('DATE', (string)$this->written()->DTSTART['VALUE']);
    }

    public function testMatchingEtagIsAcceptedAndDivergentEtagWritesNothing(): void {
        $etag = $this->store->objects[1]['e.ics']['etag'];
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'summary' => 'x', 'etag' => '"other"']), 'etag divergente');
        $this->assertNoWrites();
        self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'summary' => 'x', 'etag' => trim($etag, '"')]));
    }

    public function testRecurringSeriesRefusesTimingButAcceptsText(): void {
        $this->store->addObject(1, 'r.ics', self::ics("UID:r\nSUMMARY:Semanal\nDTSTART:20260302T100000Z\nDTEND:20260302T110000Z\nRRULE:FREQ=WEEKLY"));
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'r', 'start' => '2026-03-02T11:00:00Z']), 'série recorrente');
        $this->assertNoWrites();
        $this->assertSame('Nova', self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'r', 'summary' => 'Nova']))['summary']);
    }

    public function testAclAndClassificationRefusals(): void {
        $this->store->addObject(4, 'e.ics', self::ics("UID:ro\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        $this->store->addObject(3, 'p.ics', self::ics("UID:p\nCLASS:PRIVATE\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        $this->store->addObject(3, 'c.ics', self::ics("UID:c\nCLASS:CONFIDENTIAL\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::READONLY, 'uid' => 'ro', 'summary' => 'x']), 'Sem permissão');
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::TEAM, 'uid' => 'p', 'summary' => 'x']), 'não encontrado');
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::TEAM, 'uid' => 'c', 'summary' => 'x']), 'Sem permissão');
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'missing', 'summary' => 'x']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testTrashedObjectIsNotFound(): void {
        $this->store->addObject(2, 't.ics', self::ics("UID:t\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"), true);
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::WORK, 'uid' => 't', 'summary' => 'x']), 'não encontrado');
    }

    public function testInvalidDataIsBlocked(): void {
        $this->store->addObject(2, 'bad.ics', "BEGIN:VCALENDAR\r\nUID:bad\r\nBROKEN");
        $this->store->objects[2]['bad.ics']['data'] = "not ical\r\nUID:bad\r\n";
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::WORK, 'uid' => 'bad', 'summary' => 'x']), 'dados inválidos');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidArguments(): array {
        return [
            'no field' => [[]],
            'end before start' => [['end' => '2026-03-12T08:00:00Z']],
            'all-day without dates' => [['allDay' => true]],
            'bad zone' => [['timeZone' => 'Nowhere/City']],
        ];
    }

    /** @dataProvider invalidArguments */
    public function testInvalidArgumentsWriteNothing(array $arguments): void {
        try {
            $this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e'] + $arguments);
            $this->fail('expected CalendarArgumentException');
        } catch (CalendarArgumentException) {
            $this->assertNoWrites();
        }
    }

    public function testBackendFailurePropagates(): void {
        $this->store->failure = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'summary' => 'x']);
    }
}
