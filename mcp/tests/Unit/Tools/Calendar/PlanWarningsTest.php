<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\L10n\Translator;

/**
 * The plan of a Calendar write carries collision, availability and shared-calendar warnings.
 * They inform the user and never block the confirmed call.
 */
final class PlanWarningsTest extends CalendarTestCase {
    /** Arguments of an event on 2026-10-01 from 14:00 to 15:00 in Sao Paulo (17:00-18:00 UTC). */
    private const EVENT = ['summary' => 'Planejamento', 'start' => '2026-10-01T14:00:00-03:00', 'end' => '2026-10-01T15:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'];

    /**
     * @param array<string, mixed> $extra extra create arguments
     * @return array<string, mixed> structured plan of calendar_create_event
     */
    private function createPlan(array $extra = []): array {
        return self::json($this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL] + $extra + self::EVENT, 'alice'));
    }

    /**
     * @param list<array<string, mixed>> $warnings plan warnings
     * @param string $type warning type
     * @return list<string> messages of that type
     */
    private static function messages(array $warnings, string $type): array {
        return array_values(array_map(static fn (array $w): string => $w['message'], array_filter($warnings, static fn (array $w): bool => $w['type'] === $type)));
    }

    public function testCreateWithoutCollisionOrGuestsHasNoWarnings(): void {
        $plan = $this->createPlan();
        self::assertSame([], $plan['warnings']);
        self::assertSame([], $plan['sharedCalendars']);
        self::assertNull($plan['suggestedCalendar']);
    }

    public function testCollisionNamesTheOverlappingEventWithItsTimeInTheEventZone(): void {
        $this->store->addObject(1, 'dentist.ics', self::ics("UID:dentist\nSUMMARY:Dentista\nDTSTART:20261001T173000Z\nDTEND:20261001T183000Z"));
        $plan = $this->createPlan();
        self::assertSame([['type' => 'collision', 'message' => 'Sobrepõe Dentista (qui, 1 de out. de 2026, 14:30–15:30)']], $plan['warnings']);
        $this->assertNoWrites();
    }

    public function testCollisionIsWrittenInEnglishWhenTheAccountIsEnglish(): void {
        Translator::reset();
        $this->store->addObject(1, 'dentist.ics', self::ics("UID:dentist\nSUMMARY:Dentist\nDTSTART:20261001T173000Z\nDTEND:20261001T183000Z"));
        $plan = $this->createPlan();
        self::assertStringStartsWith('Overlaps Dentist (Thu, Oct 1, 2026, 2:30', $plan['warnings'][0]['message']);
    }

    public function testBusyOnlyCollisionNeverRevealsTheAppointment(): void {
        $this->store->addObject(3, 'secret.ics', self::ics("UID:secret\nSUMMARY:Consulta médica do Roberto\nCLASS:CONFIDENTIAL\nDTSTART:20261001T170000Z\nDTEND:20261001T180000Z"));
        $plan = self::json($this->registry->call('calendar_create_event', ['calendar' => self::TEAM, 'confirm_shared' => true] + self::EVENT, 'alice'));
        self::assertCount(1, $plan['warnings']);
        self::assertSame('collision', $plan['warnings'][0]['type']);
        self::assertStringStartsWith('Sobrepõe um compromisso ocupado (', $plan['warnings'][0]['message']);
        self::assertStringNotContainsString('Consulta', json_encode($plan, JSON_UNESCAPED_UNICODE));
    }

    public function testMoreCollisionsAreCountedBeyondTheFirstFive(): void {
        for ($i = 1; $i <= 7; $i++) {
            $this->store->addObject(1, "e$i.ics", self::ics("UID:e$i\nSUMMARY:Evento $i\nDTSTART:20261001T1700" . '00Z' . "\nDTEND:20261001T180000Z"));
        }
        $messages = self::messages($this->createPlan()['warnings'], 'collision');
        self::assertCount(6, $messages);
        self::assertSame('+ 2 mais', $messages[5]);
    }

    public function testAllDayEventCollidesWithAnyEventOfTheDay(): void {
        $this->store->addObject(1, 'dentist.ics', self::ics("UID:dentist\nSUMMARY:Dentista\nDTSTART:20261001T173000Z\nDTEND:20261001T183000Z"));
        $plan = self::json($this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Feriado', 'start' => '2026-10-01', 'end' => '2026-10-02', 'allDay' => true], 'alice'));
        self::assertSame('collision', $plan['warnings'][0]['type']);
        self::assertStringContainsString('Dentista', $plan['warnings'][0]['message']);
    }

    public function testBusyAttendeeIsNamedAndDoesNotBlock(): void {
        $this->busyEmails = ['carla@example.invalid'];
        $plan = $this->createPlan(['attendees' => ['carla']]);
        self::assertSame(['Carla Dias está ocupado(a) neste horário.'], self::messages($plan['warnings'], 'busy'));
        $this->assertNoWrites();
    }

    public function testUnverifiableAttendeeIsNamed(): void {
        $this->unknownEmails = ['carla@example.invalid'];
        $plan = $this->createPlan(['attendees' => ['carla']]);
        self::assertSame(['Não foi possível verificar a disponibilidade de Carla Dias.'], self::messages($plan['warnings'], 'unverifiable'));
    }

    public function testFailedAvailabilityCheckGivesOneGeneralWarning(): void {
        $this->availabilityFails = true;
        $plan = $this->createPlan(['attendees' => ['carla', 'dave']]);
        self::assertSame(['Não foi possível verificar a disponibilidade dos participantes.'], self::messages($plan['warnings'], 'unverifiable'));
    }

    public function testCalendarNotSharedWithTheAttendeesSuggestsTheOnlyCandidate(): void {
        // alice/team_shared_by_bob is owned by bob, so it is the only calendar bob already sees.
        $plan = $this->createPlan(['attendees' => ['bob']]);
        self::assertSame(['O calendário Pessoal não é compartilhado com Roberto Almeida. Sugestão: Equipe (bob).'], self::messages($plan['warnings'], 'calendarNotShared'));
        self::assertSame([['path' => self::TEAM, 'name' => 'Equipe (bob)', 'owner' => 'Roberto Almeida']], $plan['sharedCalendars']);
        self::assertSame(['path' => self::TEAM, 'name' => 'Equipe (bob)'], $plan['suggestedCalendar']);
    }

    public function testSeveralCandidatesAskTheModelToAskTheUser(): void {
        $this->store->addShare(2, 'principals/users/bob');
        $plan = $this->createPlan(['attendees' => ['bob']]);
        self::assertSame(['O calendário Pessoal não é compartilhado com Roberto Almeida. Pergunte ao usuário qual calendário usar.'], self::messages($plan['warnings'], 'calendarNotShared'));
        self::assertSame([['path' => self::TEAM, 'name' => 'Equipe (bob)', 'owner' => 'Roberto Almeida'], ['path' => self::WORK, 'name' => 'Trabalho']], $plan['sharedCalendars']);
        self::assertNull($plan['suggestedCalendar']);
    }

    public function testNoCandidateStillWarnsWithoutListing(): void {
        $plan = $this->createPlan(['attendees' => ['carla']]);
        self::assertSame(['O calendário Pessoal não é compartilhado com Carla Dias.'], self::messages($plan['warnings'], 'calendarNotShared'));
        self::assertSame([], $plan['sharedCalendars']);
        self::assertNull($plan['suggestedCalendar']);
    }

    public function testNothingToSayWhenTheChosenCalendarIsAlreadyShared(): void {
        $this->store->addShare(1, 'principals/users/bob');
        $plan = $this->createPlan(['attendees' => ['bob']]);
        self::assertSame([], self::messages($plan['warnings'], 'calendarNotShared'));
        self::assertSame([], $plan['sharedCalendars']);
        self::assertNull($plan['suggestedCalendar']);
    }

    public function testWarningsNeverBlockTheConfirmedCall(): void {
        $this->store->addObject(1, 'dentist.ics', self::ics("UID:dentist\nSUMMARY:Dentista\nDTSTART:20261001T173000Z\nDTEND:20261001T183000Z"));
        $this->busyEmails = ['carla@example.invalid'];
        $args = ['calendar' => self::PERSONAL, 'attendees' => ['carla']] + self::EVENT;
        self::assertNotSame([], self::json($this->registry->call('calendar_create_event', $args, 'alice'))['warnings']);
        $this->assertNoWrites();
        $result = $this->registry->call('calendar_create_event', $args + ['confirm' => true], 'alice');
        self::assertArrayNotHasKey('isError', $result);
        self::assertCount(1, $this->dav->calls);
    }

    public function testWarningsAreRenderedAsTextForThePerson(): void {
        $this->store->addObject(1, 'dentist.ics', self::ics("UID:dentist\nSUMMARY:Dentista\nDTSTART:20261001T173000Z\nDTEND:20261001T183000Z"));
        $this->busyEmails = ['bob@example.invalid'];
        $result = $this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL, 'attendees' => ['bob']] + self::EVENT, 'alice');
        $text = $result['content'][0]['text'];
        self::assertStringContainsString('### Avisos', $text);
        self::assertStringContainsString('- Participantes: Roberto Almeida', $text);
        self::assertStringNotContainsStringIgnoringCase('mailto', $text);
        self::assertStringContainsString('Sobrepõe Dentista', $text);
        self::assertStringContainsString('Roberto Almeida está ocupado(a) neste horário.', $text);
        self::assertStringContainsString('### Calendários compartilhados com os participantes', $text);
        self::assertStringContainsString('- Equipe (bob), de Roberto Almeida', $text);
        self::assertStringContainsString('Sugestão: usar *Equipe (bob)*', $text);
        // The DAV path stays out of what the person reads; it is in the last line, for the model that repeats the call.
        [$person, $model] = explode('Nada foi alterado. Confirme para executar.', $text, 2);
        self::assertStringNotContainsString('/remote.php', $person);
        self::assertStringContainsString('repita a chamada com calendar `/remote.php/dav/calendars/alice/team_shared_by_bob/` (Equipe (bob))', $model);
    }

    public function testFailureOfAWarningSourceNeverBreaksThePlan(): void {
        $this->store->rangeFailure = new \RuntimeException('range lookup failed');
        $this->store->sharesFailure = new \RuntimeException('shares lookup failed');
        $plan = $this->createPlan(['attendees' => ['bob']]);
        self::assertTrue($plan['requiresConfirmation']);
        self::assertSame([], self::messages($plan['warnings'], 'collision'));
        self::assertSame([], self::messages($plan['warnings'], 'calendarNotShared'));
    }

    // ---- update ----

    private function seedEvent(string $extra = ''): void {
        $this->store->addObject(1, 'event.ics', self::ics("UID:event\nSUMMARY:Reunião\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z" . $extra));
    }

    public function testUpdateOfTextAloneAddsNoWarnings(): void {
        $this->seedEvent();
        $this->store->addObject(1, 'other.ics', self::ics("UID:other\nSUMMARY:Outro\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z"));
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'Novo'], 'alice'));
        self::assertSame([], $plan['warnings']);
    }

    public function testUpdateOfTimingChecksCollisionsExcludingTheEventItself(): void {
        $this->seedEvent();
        $this->store->addObject(1, 'other.ics', self::ics("UID:other\nSUMMARY:Outro\nDTSTART:20261001T140000Z\nDTEND:20261001T150000Z"));
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T12:30:00Z', 'end' => '2026-10-01T14:30:00Z'], 'alice'));
        self::assertCount(1, $plan['warnings']);
        self::assertStringContainsString('Outro', $plan['warnings'][0]['message']);
        self::assertStringNotContainsString('Reunião', $plan['warnings'][0]['message']);
    }

    public function testUpdateOfTimingChecksTheAvailabilityOfTheCurrentGuests(): void {
        $this->seedEvent("\nATTENDEE;CN=Carla Dias:mailto:carla@example.invalid");
        $this->busyEmails = ['carla@example.invalid'];
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T15:00:00Z', 'end' => '2026-10-01T16:00:00Z'], 'alice'));
        self::assertSame(['Carla Dias está ocupado(a) neste horário.'], self::messages($plan['warnings'], 'busy'));
    }

    /** The guest's own copy of the event is a busy block of the core free/busy; it must not be reported as a conflict. */
    public function testUpdateInsideTheCurrentSlotDoesNotReportTheGuestBusyByTheEventItself(): void {
        $this->seedEvent("\nATTENDEE;CN=Carla Dias:mailto:carla@example.invalid");
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-01T12:00:00Z', '2026-10-01T13:00:00Z']]];
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T12:15:00Z', 'end' => '2026-10-01T12:45:00Z'], 'alice'));
        self::assertSame([], self::messages($plan['warnings'], 'busy'));
        self::assertSame([], $this->availabilityAsked, 'nothing outside the own slot is left to ask');
    }

    public function testUpdateOverlappingTheCurrentSlotOnlyAsksAboutTheNewPart(): void {
        $this->seedEvent("\nATTENDEE;CN=Carla Dias:mailto:carla@example.invalid");
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-01T12:00:00Z', '2026-10-01T13:00:00Z']]];
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T12:30:00Z', 'end' => '2026-10-01T13:30:00Z'], 'alice'));
        self::assertSame([], self::messages($plan['warnings'], 'busy'));
        self::assertSame([['2026-10-01T13:00:00Z', '2026-10-01T13:30:00Z']], $this->availabilityAsked);
    }

    public function testUpdateStillReportsAnotherAppointmentOfTheGuestOutsideTheCurrentSlot(): void {
        $this->seedEvent("\nATTENDEE;CN=Carla Dias:mailto:carla@example.invalid");
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-01T12:00:00Z', '2026-10-01T13:00:00Z'], ['2026-10-01T13:10:00Z', '2026-10-01T13:20:00Z']]];
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T12:30:00Z', 'end' => '2026-10-01T13:30:00Z'], 'alice'));
        self::assertSame(['Carla Dias está ocupado(a) neste horário.'], self::messages($plan['warnings'], 'busy'));
    }

    public function testUpdateAskingBeforeAndAfterTheCurrentSlotAsksBothParts(): void {
        $this->seedEvent("\nATTENDEE;CN=Carla Dias:mailto:carla@example.invalid");
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-01T12:00:00Z', '2026-10-01T13:00:00Z']]];
        self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T11:30:00Z', 'end' => '2026-10-01T13:30:00Z'], 'alice'));
        self::assertSame([['2026-10-01T11:30:00Z', '2026-10-01T12:00:00Z'], ['2026-10-01T13:00:00Z', '2026-10-01T13:30:00Z']], $this->availabilityAsked);
    }

    /** A guest who is not in the stored event has no copy of it: the whole new range is asked, own slot included. */
    public function testUpdateAddingAGuestStillChecksTheCurrentSlotForThem(): void {
        $this->seedEvent();
        $this->busyBlocks = ['bob@example.invalid' => [['2026-10-01T12:00:00Z', '2026-10-01T13:00:00Z']]];
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'attendees' => ['bob']], 'alice'));
        self::assertSame(['Roberto Almeida está ocupado(a) neste horário.'], self::messages($plan['warnings'], 'busy'));
    }

    public function testCreateStillChecksTheWholeRangeOfEveryGuest(): void {
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-01T17:00:00Z', '2026-10-01T18:00:00Z']]];
        $plan = $this->createPlan(['attendees' => ['carla']]);
        self::assertSame(['Carla Dias está ocupado(a) neste horário.'], self::messages($plan['warnings'], 'busy'));
        self::assertSame([['2026-10-01T17:00:00Z', '2026-10-01T18:00:00Z']], $this->availabilityAsked);
    }

    public function testUpdateOfGuestsChecksAvailabilityAndSharing(): void {
        $this->seedEvent();
        $this->busyEmails = ['bob@example.invalid'];
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'attendees' => ['bob']], 'alice'));
        self::assertSame(['Roberto Almeida está ocupado(a) neste horário.'], self::messages($plan['warnings'], 'busy'));
        self::assertSame('calendarNotShared', $plan['warnings'][1]['type']);
        self::assertSame(['path' => self::TEAM, 'name' => 'Equipe (bob)'], $plan['suggestedCalendar']);
    }

    public function testUpdateWarningsNeverBlockTheConfirmedCall(): void {
        $this->seedEvent();
        $this->store->addObject(1, 'other.ics', self::ics("UID:other\nSUMMARY:Outro\nDTSTART:20261001T140000Z\nDTEND:20261001T150000Z"));
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T14:00:00Z', 'end' => '2026-10-01T15:00:00Z'];
        self::assertNotSame([], self::json($this->registry->call('calendar_update_event', $args, 'alice'))['warnings']);
        self::assertArrayNotHasKey('isError', $this->registry->call('calendar_update_event', $args + ['confirm' => true], 'alice'));
        self::assertCount(1, $this->dav->calls);
    }

    // ---- move, transfer, delete ----

    public function testMoveChecksCollisionsInTheDestinationCalendar(): void {
        $this->seedEvent();
        $this->store->addObject(2, 'clash.ics', self::ics("UID:clash\nSUMMARY:Conflito no trabalho\nDTSTART:20261001T123000Z\nDTEND:20261001T133000Z"));
        $plan = self::json($this->registry->call('calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::WORK], 'alice'));
        self::assertSame('collision', $plan['warnings'][0]['type']);
        self::assertStringContainsString('Conflito no trabalho', $plan['warnings'][0]['message']);
        self::assertArrayNotHasKey('isError', $this->registry->call('calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::WORK, 'confirm' => true], 'alice'));
        self::assertCount(1, $this->dav->calls);
    }

    public function testMoveToACalendarWithoutClashHasNoWarnings(): void {
        $this->seedEvent();
        $plan = self::json($this->registry->call('calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::WORK], 'alice'));
        self::assertSame([], $plan['warnings']);
    }

    public function testTransferChecksCollisionsInTheDestinationCalendar(): void {
        $this->seedEvent();
        $this->store->addObject(3, 'clash.ics', self::ics("UID:clash\nSUMMARY:Reunião da equipe\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z"));
        $plan = self::json($this->registry->call('calendar_transfer_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::TEAM, 'confirm_shared' => true], 'alice'));
        self::assertStringContainsString('Reunião da equipe', $plan['warnings'][0]['message']);
    }

    public function testDeleteHasNoWarnings(): void {
        $this->seedEvent();
        $plan = self::json($this->registry->call('calendar_delete_event', ['calendar' => self::PERSONAL, 'uid' => 'event'], 'alice'));
        self::assertArrayNotHasKey('warnings', $plan);
        self::assertArrayNotHasKey('sharedCalendars', $plan);
        self::assertArrayNotHasKey('suggestedCalendar', $plan);
    }

    public function testTheGuideExplainsTheWarningsToTheModel(): void {
        $notes = implode(' ', $this->module->guideNotes());
        foreach (['warnings', 'collision', 'busy', 'suggestedCalendar', 'sharedCalendars', 'never block'] as $term) {
            self::assertStringContainsString($term, $notes);
        }
    }

    /** A title or a name typed by somebody else enters the warning flat and bounded; the plan text escapes it once. */
    public function testWarningMessagesFlattenAndBoundTheNamesTheyQuote(): void {
        $title = "Dentista\n### Forged " . str_repeat('x', 400);

        $overlap = \OCA\Mcp\Tools\Calendar\CalendarMessages::collisionWith($title, 'qui, 14:00');
        $busy = \OCA\Mcp\Tools\Calendar\CalendarMessages::attendeeBusy("Roberto\r\n# x");

        self::assertStringNotContainsString("\n", $overlap);
        self::assertLessThan(200, mb_strlen($overlap));
        self::assertStringEndsWith('… (qui, 14:00)', $overlap);
        self::assertStringNotContainsString("\n", $busy);
        self::assertStringContainsString('Roberto # x', $busy);
    }
}
