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
        [$method, $arguments] = $this->dav->calls[0];
        $uri = $item['uid'] . '.ics';
        $this->assertSame('put', $method);
        $this->assertSame(['personal', $uri, false], [$arguments[0], $arguments[1], $arguments[3]]);
        $this->assertSame($this->store->objects[1][$uri]['etag'], $item['etag']);
        $this->assertSame(['requested' => false, 'imipEnabled' => true, 'message' => 'nenhum convite foi agendado'], $item['scheduling']);
        $data = $arguments[2];
        $event = Reader::read($data)->VEVENT;
        $this->assertSame('America/Sao_Paulo', (string)$event->DTSTART['TZID']);
        $this->assertSame('20260312T090000', $event->DTSTART->getValue());
        $this->assertSame(['Centro', 'Levar exames', '0'], [(string)$event->LOCATION, (string)$event->DESCRIPTION, (string)$event->SEQUENCE]);
        $this->assertFalse(isset($event->RRULE));
    }

    public function testCreatesAllDayEventInSharedWritableCalendar(): void {
        $item = self::json($this->call('calendar_create_event', ['calendar' => self::TEAM, 'summary' => 'Feriado', 'start' => '2026-04-21', 'end' => '2026-04-22', 'allDay' => true, 'confirm_shared' => true]));
        $this->assertSame(['2026-04-21', '2026-04-22', true], [$item['start'], $item['end'], $item['allDay']]);
        $event = Reader::read($this->dav->calls[0][1][2])->VEVENT;
        $this->assertSame(['DATE', '20260421'], [(string)$event->DTSTART['VALUE'], $event->DTSTART->getValue()]);
    }

    public function testSharedCalendarWithoutConfirmationWritesNothing(): void {
        $payload = self::json($this->call('calendar_create_event', [
            'calendar' => self::TEAM, 'summary' => 'Feriado', 'start' => '2026-04-21', 'end' => '2026-04-22', 'allDay' => true,
        ]));

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

    public function testSharedCalendarWithConfirmationCreatesTheEvent(): void {
        $item = self::json($this->call('calendar_create_event', [
            'calendar' => self::TEAM, 'summary' => 'Feriado', 'start' => '2026-04-21', 'end' => '2026-04-22', 'allDay' => true, 'confirm_shared' => true,
        ]));

        $this->assertSame(['put', 'team_shared_by_bob'], [$this->dav->calls[0][0], $this->dav->calls[0][1][0]]);
        $this->assertSame(self::TEAM, $item['calendar']);
    }

    public function testReadOnlyBirthdayAndForeignCalendarsAreRefused(): void {
        $args = ['summary' => 'x', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z'];
        self::assertToolError($this->call('calendar_create_event', ['calendar' => self::READONLY] + $args), 'Sem permissão');
        self::assertToolError($this->call('calendar_create_event', ['calendar' => self::BIRTHDAYS] + $args), 'Sem permissão');
        self::assertToolError($this->call('calendar_create_event', ['calendar' => '/remote.php/dav/calendars/bob/team/'] + $args), 'não encontrado');
        // The shared-resource confirmation never replaces the ACL: a refused calendar stays refused.
        self::assertToolError($this->call('calendar_create_event', ['calendar' => self::READONLY, 'confirm_shared' => true] + $args), 'Sem permissão');
        $this->assertNoWrites();
    }

    public function testAttendeesBecomeAttendeePropertiesAndAnOrganizer(): void {
        $item = self::json($this->call('calendar_create_event', [
            'calendar' => self::PERSONAL, 'summary' => 'Revisao', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
            'attendees' => ['bob', 'carla'],
        ]));

        $event = Reader::read($this->dav->calls[0][1][2])->VEVENT;
        $this->assertSame('mailto:alice@example.invalid', (string)$event->ORGANIZER);
        $this->assertSame('Alice Silva', (string)$event->ORGANIZER['CN']);
        $this->assertCount(2, $event->select('ATTENDEE'));
        $bob = $event->select('ATTENDEE')[0];
        $this->assertSame('mailto:bob@example.invalid', (string)$bob);
        $this->assertSame(
            ['Roberto Almeida', 'INDIVIDUAL', 'REQ-PARTICIPANT', 'NEEDS-ACTION', 'TRUE'],
            [(string)$bob['CN'], (string)$bob['CUTYPE'], (string)$bob['ROLE'], (string)$bob['PARTSTAT'], (string)$bob['RSVP']],
        );
        $this->assertSame('mailto:carla@example.invalid', (string)$event->select('ATTENDEE')[1]);
        // Suppressed by default: the guest list is written, and no guest carries a SCHEDULE-STATUS,
        // which is what proves the Nextcloud scheduler was told to send nothing.
        $this->assertSame([
            'requested' => false,
            'imipEnabled' => true,
            'message' => 'nenhum convite foi agendado',
            'participants' => [
                ['email' => 'mailto:bob@example.invalid', 'scheduleStatus' => null, 'meaning' => 'sem registro de envio'],
                ['email' => 'mailto:carla@example.invalid', 'scheduleStatus' => null, 'meaning' => 'sem registro de envio'],
            ],
        ], $item['scheduling']);
        $this->assertFalse($this->dav->calls[0][1][3], 'scheduling must be off when send_invitations is absent');
    }

    public function testSendInvitationsTrueReportsWhatWasHandedOverWithoutClaimingDelivery(): void {
        $item = self::json($this->call('calendar_create_event', [
            'calendar' => self::PERSONAL, 'summary' => 'Revisao', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
            'attendees' => ['bob'], 'send_invitations' => true,
        ]));

        $this->assertTrue($this->dav->calls[0][1][3]);
        $this->assertSame([
            'requested' => true,
            'imipEnabled' => true,
            'message' => 'convite entregue ao agendamento do Nextcloud',
            'participants' => [['email' => 'mailto:bob@example.invalid', 'scheduleStatus' => null, 'meaning' => 'sem registro de envio']],
        ], $item['scheduling']);
    }

    public function testDisabledImipIsReportedInsteadOfClaimingAnEmailWasSent(): void {
        $this->sendInvitations = 'no';

        $item = self::json($this->call('calendar_create_event', [
            'calendar' => self::PERSONAL, 'summary' => 'Revisao', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
            'attendees' => ['bob'], 'send_invitations' => true,
        ]));

        $this->assertFalse($item['scheduling']['imipEnabled']);
        $this->assertStringContainsString('envio de convites por e-mail desligado', $item['scheduling']['message']);
    }

    public function testARefusedGuestListWritesNothing(): void {
        foreach ([['ghost'], ['alice'], ['nobody@example.invalid']] as $guests) {
            try {
                $this->call('calendar_create_event', [
                    'calendar' => self::PERSONAL, 'summary' => 'x', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
                    'attendees' => $guests,
                ]);
                $this->fail('expected the guest list to be refused: ' . implode(',', $guests));
            } catch (CalendarArgumentException) {
                $this->assertNoWrites();
            }
        }
    }

    public function testTheDescriptionTellsTheAgentThatTheCallCanInvitePeople(): void {
        $definition = $this->writeHandlers['calendar_create_event']->definition();

        $this->assertTrue($definition['destructiveHint']);
        $this->assertStringContainsString('This call may send invitations to other people', $definition['description']);
        // The properties are an object on the wire, so the agent sees them as a JSON object.
        $properties = (array)$definition['inputSchema']['properties'];
        $this->assertFalse($properties['send_invitations']['default']);
        $this->assertSame(1, $properties['attendees']['minItems']);
        $this->assertSame(50, $properties['attendees']['maxItems']);
        $this->assertTrue($properties['attendees']['uniqueItems']);
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
        $this->dav->failureFor['put'] = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'x', 'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z']);
    }
}
