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
        return Reader::read($this->dav->calls[0][1][3])->VEVENT;
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
        $this->assertSame(['update', 'personal', 'e.ics'], [$this->dav->calls[0][0], $this->dav->calls[0][1][0], $this->dav->calls[0][1][1]]);
        // If-Match always carries the ETag that was read before the write, even when the client sent
        // none: a concurrent change between the read and the write has to lose, not win.
        self::assertNotSame($item['etag'], $this->dav->calls[0][1][2]);
        $this->assertFalse($this->dav->calls[0][1][4], 'scheduling is off by default');
        $this->assertSame($this->store->objects[1]['e.ics']['etag'], $item['etag']);
        // With scheduling suppressed the guest is still reported, and it carries no SCHEDULE-STATUS:
        // that absence is the proof that the Nextcloud scheduler was told not to send anything.
        $this->assertSame([
            'requested' => false,
            'imipEnabled' => true,
            'message' => 'nenhum convite foi agendado',
            'participants' => [['email' => 'mailto:bob@example.com', 'scheduleStatus' => null, 'meaning' => 'sem registro de envio']],
        ], $item['scheduling']);
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
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::TEAM, 'uid' => 'p', 'summary' => 'x', 'confirm_shared' => true]), 'não encontrado');
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::TEAM, 'uid' => 'c', 'summary' => 'x', 'confirm_shared' => true]), 'Sem permissão');
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'missing', 'summary' => 'x']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testSharedCalendarWithoutConfirmationUpdatesNothing(): void {
        $payload = self::json($this->call('calendar_update_event', ['calendar' => self::TEAM, 'uid' => 'c', 'summary' => 'Novo']));

        $this->assertTrue($payload['requiresConfirmation']);
        $this->assertSame(['shared', 'bob', 'Roberto Almeida', 'Equipe (bob)'], [
            $payload['scope'], $payload['owner'], $payload['ownerDisplayName'], $payload['resource'],
        ]);
        $this->assertSame(
            "O calendário 'Equipe (bob)' pertence a Roberto Almeida e é compartilhado com você. Alterações afetam outras pessoas."
            . ' Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.',
            $payload['message'],
        );
        $this->assertNoWrites();
    }

    public function testSharedCalendarWithConfirmationUpdatesTheEvent(): void {
        $this->store->addObject(3, 'g.ics', self::ics("UID:g\nSUMMARY:Antigo\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));

        $item = self::json($this->call('calendar_update_event', [
            'calendar' => self::TEAM, 'uid' => 'g', 'summary' => 'Novo', 'confirm_shared' => true,
        ]));

        $this->assertSame('Novo', $item['summary']);
        $this->assertSame(['update', 'team_shared_by_bob', 'g.ics'], [
            $this->dav->calls[0][0], $this->dav->calls[0][1][0], $this->dav->calls[0][1][1],
        ]);
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

    public function testAttendeesReplaceTheListAndKeepTheAnswersOfThoseWhoStay(): void {
        $this->store->addObject(1, 'g.ics', self::ics(
            "UID:g\nSUMMARY:Revisao\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\n"
            . "ORGANIZER:mailto:alice@example.invalid\n"
            . "ATTENDEE;CN=Bob;PARTSTAT=ACCEPTED:mailto:bob@example.invalid\n"
            . "ATTENDEE;CN=Carla;PARTSTAT=DECLINED:mailto:carla@example.invalid"
        ));

        $item = self::json($this->call('calendar_update_event', [
            'calendar' => self::PERSONAL, 'uid' => 'g', 'attendees' => ['bob', 'dave'],
        ]));

        $event = $this->written();
        $this->assertSame(['mailto:alice@example.invalid', 2], [(string)$event->ORGANIZER, count($event->select('ATTENDEE'))]);
        // Bob stays and keeps his answer; Carla is out and Dave arrives needing an answer.
        $this->assertSame(['mailto:bob@example.invalid', 'ACCEPTED'], [(string)$event->select('ATTENDEE')[0], (string)$event->select('ATTENDEE')[0]['PARTSTAT']]);
        $this->assertSame(['mailto:dave@example.invalid', 'NEEDS-ACTION'], [(string)$event->select('ATTENDEE')[1], (string)$event->select('ATTENDEE')[1]['PARTSTAT']]);
        $this->assertSame(['dave' => true], ['dave' => isset($item['scheduling']['participants'])]);
    }

    public function testAnEmptyGuestListRemovesEveryone(): void {
        $this->store->objects[1]['e.ics']['data'] = str_replace('alice@example.com', 'alice@example.invalid', $this->store->objects[1]['e.ics']['data']);
        $item = self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'attendees' => []]));

        $this->assertFalse(isset($this->written()->ATTENDEE));
        $this->assertArrayNotHasKey('participants', $item['scheduling']);
    }

    public function testGuestChangesAreRefusedOnASeriesAndBySomebodyWhoIsNotTheOrganizer(): void {
        $this->store->addObject(1, 'r.ics', self::ics("UID:r\nDTSTART:20260302T100000Z\nDTEND:20260302T110000Z\nRRULE:FREQ=WEEKLY"));
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'r', 'attendees' => ['bob']]), 'série recorrente');
        self::assertNoWrites();

        // The event of the fixture is organized by alice@example.com, which is not alice@...invalid.
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'attendees' => ['carla']]), 'organizador');
        $this->assertNoWrites();
    }

    public function testRemovingGuestsCannotBypassRecurringAndOrganizerGuards(): void {
        $this->store->addObject(1, 'r.ics', self::ics("UID:r\nDTSTART:20260302T100000Z\nDTEND:20260302T110000Z\nRRULE:FREQ=WEEKLY\nATTENDEE:mailto:bob@example.invalid"));
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'r', 'attendees' => []]), 'série recorrente');
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'attendees' => []]), 'organizador');
        $this->assertNoWrites();
    }

    public function testUpdateSchemaAcceptsEmptyAttendeesToRemoveEveryone(): void {
        $schema = $this->writeHandlers['calendar_update_event']->definition()['inputSchema'];
        $validated = \OCA\Mcp\Tools\ArgumentValidator::validate($schema, ['calendar' => self::PERSONAL, 'uid' => 'e', 'attendees' => []]);
        self::assertSame([], $validated['attendees']);
    }

    public function testRefusedGuestListWritesNothing(): void {
        $this->store->addObject(1, 'o.ics', self::ics("UID:o\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\nORGANIZER:mailto:alice@example.invalid"));

        // An unresolvable guest is a bad argument (-32602), not a tool error result.
        try {
            $this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'o', 'attendees' => ['ghost']]);
            $this->fail('expected CalendarArgumentException');
        } catch (CalendarArgumentException $exception) {
            $this->assertStringContainsString('ghost', $exception->getMessage());
        }
        $this->assertNoWrites();
    }

    public function testTheOrganizerIsCheckedBeforeTheGuestListIsResolved(): void {
        // Somebody who does not organize the event learns nothing about which accounts exist.
        self::assertToolError($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'attendees' => ['ghost']]), 'organizador');
        $this->assertNoWrites();
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
        $this->dav->failureFor['update'] = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'e', 'summary' => 'x']);
    }
}
