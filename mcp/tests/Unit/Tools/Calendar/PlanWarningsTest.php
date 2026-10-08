<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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

    /**
     * @param string $day civil day of the all-day event, Y-m-d
     * @return list<array<string, mixed>> collision warnings of the plan of an all-day event on that day, built the way the registry builds it
     */
    private function allDayCollisions(string $day = '2026-10-01'): array {
        $next = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
        $plan = self::json($this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Feriado', 'start' => $day, 'end' => $next, 'allDay' => true], 'alice'));
        return array_values(array_filter($plan['warnings'], static fn (array $w): bool => $w['type'] === 'collision'));
    }

    /** The day of an all-day event is the day in the account's zone: 22:00 of the day before there is not on it, 23:00 of the day is. */
    public function testAllDayEventCollidesByTheDayOfTheAccountZoneNotByTheDayInUtc(): void {
        // 30/09 22:00-23:00 in Sao Paulo is 01/10 01:00-02:00 UTC: the day before, though it falls on 01/10 in UTC.
        $this->store->addObject(1, 'night30.ics', self::ics("UID:night30\nSUMMARY:Noite do dia 30\nDTSTART:20261001T010000Z\nDTEND:20261001T020000Z"));
        // 01/10 23:00-23:30 in Sao Paulo is 02/10 02:00-02:30 UTC: on the day, though it falls on 02/10 in UTC.
        $this->store->addObject(1, 'night1.ics', self::ics("UID:night1\nSUMMARY:Noite do dia 1\nDTSTART:20261002T020000Z\nDTEND:20261002T023000Z"));

        $collisions = $this->allDayCollisions();

        self::assertCount(1, $collisions);
        self::assertStringContainsString('Noite do dia 1', $collisions[0]['message']);
        self::assertStringNotContainsString('Noite do dia 30', $collisions[0]['message']);
    }

    /** The same holds east of UTC, where the day starts the evening before in UTC. */
    public function testAllDayEventUsesTheZoneOfAnAccountEastOfUtc(): void {
        $this->accountTimezone = 'Asia/Tokyo';
        // 01/10 00:30-01:30 in Tokyo is 30/09 15:30-16:30 UTC.
        $this->store->addObject(1, 'dawn.ics', self::ics("UID:dawn\nSUMMARY:Madrugada\nDTSTART:20260930T153000Z\nDTEND:20260930T163000Z"));
        // 02/10 00:30 in Tokyo is 01/10 15:30 UTC: the next day, though it falls on 01/10 in UTC.
        $this->store->addObject(1, 'next.ics', self::ics("UID:next\nSUMMARY:Dia seguinte\nDTSTART:20261001T153000Z\nDTEND:20261001T163000Z"));

        $collisions = $this->allDayCollisions();

        self::assertCount(1, $collisions);
        self::assertStringContainsString('Madrugada', $collisions[0]['message']);
    }

    /** All-day events compare day with day: the one of the next day does not collide, the one of the same day does, in any zone. */
    public function testAllDayEventsCollideOnlyOnTheSameCivilDay(): void {
        foreach (['America/Sao_Paulo', 'Asia/Tokyo', 'UTC'] as $zone) {
            $this->accountTimezone = $zone;
            $this->store->objects[1] = [];
            $this->store->addObject(1, 'same.ics', self::ics("UID:same\nSUMMARY:Mesmo dia\nDTSTART;VALUE=DATE:20261001\nDTEND;VALUE=DATE:20261002"));
            $this->store->addObject(1, 'before.ics', self::ics("UID:before\nSUMMARY:Dia anterior\nDTSTART;VALUE=DATE:20260930\nDTEND;VALUE=DATE:20261001"));
            $this->store->addObject(1, 'after.ics', self::ics("UID:after\nSUMMARY:Dia seguinte\nDTSTART;VALUE=DATE:20261002\nDTEND;VALUE=DATE:20261003"));

            $collisions = $this->allDayCollisions();

            self::assertCount(1, $collisions, $zone);
            self::assertStringContainsString('Mesmo dia', $collisions[0]['message'], $zone);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> account zone, start and end of the timed event in
     *   its own zone, the all-day event that shares its civil day and the one of the neighbouring day that does not
     */
    public static function timedEventOverAllDayEvents(): array {
        return [
            // 22:00-23:00 of 05/10 in Sao Paulo is 06/10 01:00-02:00 UTC: on the day 05, though it falls on 06 in UTC.
            'Sao Paulo, late evening' => ['America/Sao_Paulo', '2026-10-05T22:00:00-03:00', '2026-10-05T23:00:00-03:00', '20261005', '20261006'],
            // 06/10 22:00 in Sao Paulo is 07/10 01:00 UTC: on the day 06, where a UTC comparison no longer finds it.
            'Sao Paulo, next evening' => ['America/Sao_Paulo', '2026-10-06T22:00:00-03:00', '2026-10-06T23:00:00-03:00', '20261006', '20261007'],
            // 06/10 00:30-01:30 in Tokyo is 05/10 15:30-16:30 UTC: on the day 06, though it falls on 05 in UTC.
            'Tokyo, early morning' => ['Asia/Tokyo', '2026-10-06T00:30:00+09:00', '2026-10-06T01:30:00+09:00', '20261006', '20261005'],
        ];
    }

    /** A timed event is compared with an all-day event by the day in the zone of the account, as an all-day event is with a timed one. */
    #[\PHPUnit\Framework\Attributes\DataProvider('timedEventOverAllDayEvents')]
    public function testTimedEventCollidesWithAnAllDayEventByTheDayOfTheAccountZone(string $zone, string $start, string $end, string $sameDay, string $otherDay): void {
        $this->accountTimezone = $zone;
        $next = static fn (string $day): string => (new \DateTimeImmutable($day))->modify('+1 day')->format('Ymd');
        $this->store->addObject(1, 'same.ics', self::ics("UID:same\nSUMMARY:Mesmo dia\nDTSTART;VALUE=DATE:$sameDay\nDTEND;VALUE=DATE:" . $next($sameDay)));
        $this->store->addObject(1, 'other.ics', self::ics("UID:other\nSUMMARY:Outro dia\nDTSTART;VALUE=DATE:$otherDay\nDTEND;VALUE=DATE:" . $next($otherDay)));

        $collisions = array_values(array_filter(
            $this->createPlan(['start' => $start, 'end' => $end, 'timeZone' => $zone])['warnings'],
            static fn (array $w): bool => $w['type'] === 'collision',
        ));

        self::assertCount(1, $collisions, $zone);
        self::assertStringContainsString('Mesmo dia', $collisions[0]['message']);
        self::assertStringNotContainsString('Outro dia', $collisions[0]['message']);
    }

    /** The title of a private occurrence of somebody else's series never reaches the plan, and the occurrence does not collide. */
    public function testPrivateOverrideOfAnotherPersonsSeriesIsNotInThePlan(): void {
        $master = "UID:serie\nSUMMARY:Serie publica\nDTSTART:20260930T090000Z\nDTEND:20260930T100000Z\nRRULE:FREQ=DAILY;COUNT=3";
        // 01/10 14:00-15:00 in Sao Paulo, the very slot of the new event, moved by its organiser and marked private.
        $override = "BEGIN:VEVENT\nUID:serie\nRECURRENCE-ID:20261001T090000Z\nCLASS:PRIVATE\nSUMMARY:Segredo do Roberto\nDTSTART:20261001T170000Z\nDTEND:20261001T180000Z\nEND:VEVENT";
        $this->store->addObject(3, 'serie.ics', self::ics($master, $override));

        $result = $this->registry->call('calendar_create_event', ['calendar' => self::TEAM, 'confirm_shared' => true] + self::EVENT, 'alice');

        self::assertSame([], self::json($result)['warnings']);
        self::assertStringNotContainsString('Segredo do Roberto', json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    /** The plan does not reveal an appointment whose CLASS is unknown either: it is handled as private. */
    public function testUnknownClassOfAnotherPersonsEventIsNotInThePlan(): void {
        $this->store->addObject(3, 'x.ics', self::ics("UID:x\nCLASS:X-FOO\nSUMMARY:Segredo desconhecido\nDTSTART:20261001T170000Z\nDTEND:20261001T180000Z"));
        $result = $this->registry->call('calendar_create_event', ['calendar' => self::TEAM, 'confirm_shared' => true] + self::EVENT, 'alice');
        self::assertSame([], self::json($result)['warnings']);
        self::assertStringNotContainsString('Segredo desconhecido', json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    /** A confidential occurrence collides as a busy block without its title. */
    public function testConfidentialOverrideOfAnotherPersonsSeriesCollidesAsBusy(): void {
        $master = "UID:serie\nSUMMARY:Serie publica\nDTSTART:20260930T090000Z\nDTEND:20260930T100000Z\nRRULE:FREQ=DAILY;COUNT=3";
        $override = "BEGIN:VEVENT\nUID:serie\nRECURRENCE-ID:20261001T090000Z\nCLASS:CONFIDENTIAL\nSUMMARY:Segredo do Roberto\nDTSTART:20261001T170000Z\nDTEND:20261001T180000Z\nEND:VEVENT";
        $this->store->addObject(3, 'serie.ics', self::ics($master, $override));

        $result = $this->registry->call('calendar_create_event', ['calendar' => self::TEAM, 'confirm_shared' => true] + self::EVENT, 'alice');

        $plan = self::json($result);
        self::assertCount(1, $plan['warnings']);
        self::assertStringStartsWith('Sobrepõe um compromisso ocupado (', $plan['warnings'][0]['message']);
        self::assertStringNotContainsString('Segredo do Roberto', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('Segredo do Roberto', $result['content'][0]['text']);
    }

    /**
     * @param string $zone zone of alice's account
     * @return array<string, array{0: string, 1: string, 2: string}> account zone, UTC start and UTC end of 01/10 in that zone
     */
    public static function dayInEachZone(): array {
        return [
            'Sao Paulo' => ['America/Sao_Paulo', '2026-10-01T03:00:00Z', '2026-10-02T03:00:00Z'],
            'Tokyo' => ['Asia/Tokyo', '2026-09-30T15:00:00Z', '2026-10-01T15:00:00Z'],
        ];
    }

    /** The guests of an all-day event are asked about the day in the zone of the account, not about the day in UTC. */
    #[\PHPUnit\Framework\Attributes\DataProvider('dayInEachZone')]
    public function testAllDayEventAsksTheGuestsAboutTheDayInTheAccountZone(string $zone, string $from, string $to): void {
        $this->accountTimezone = $zone;
        self::json($this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Feriado', 'start' => '2026-10-01', 'end' => '2026-10-02', 'allDay' => true, 'attendees' => ['carla']], 'alice'));
        self::assertSame([[$from, $to]], $this->availabilityAsked);
    }

    /** A guest busy the evening before the day (in the zone of the account) is not busy on it; one busy late on the day is. */
    public function testAllDayEventReportsAGuestBusyOnlyWithinTheDayOfTheAccountZone(): void {
        $args = ['calendar' => self::PERSONAL, 'summary' => 'Feriado', 'start' => '2026-10-01', 'end' => '2026-10-02', 'allDay' => true, 'attendees' => ['carla']];
        // 30/09 22:00-23:00 in Sao Paulo is 01/10 01:00-02:00 UTC: inside the UTC day, outside the day of the account.
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-01T01:00:00Z', '2026-10-01T02:00:00Z']]];
        self::assertSame([], self::messages(self::json($this->registry->call('calendar_create_event', $args, 'alice'))['warnings'], 'busy'));
        // 01/10 23:00-23:30 in Sao Paulo is 02/10 02:00-02:30 UTC: outside the UTC day, inside the day of the account.
        $this->busyBlocks = ['carla@example.invalid' => [['2026-10-02T02:00:00Z', '2026-10-02T02:30:00Z']]];
        self::assertSame(['Carla Dias está ocupado(a) neste horário.'], self::messages(self::json($this->registry->call('calendar_create_event', $args, 'alice'))['warnings'], 'busy'));
    }

    /** The current slot of a stored all-day event is the same day in the same zone, so only the days added are asked. */
    public function testUpdateExtendingAnAllDayEventAsksOnlyAboutTheDaysAdded(): void {
        $this->store->addObject(1, 'event.ics', self::ics("UID:event\nSUMMARY:Feriado\nDTSTART;VALUE=DATE:20261001\nDTEND;VALUE=DATE:20261002\nATTENDEE;CN=Carla Dias:mailto:carla@example.invalid"));
        self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01', 'end' => '2026-10-03', 'allDay' => true], 'alice'));
        self::assertSame([['2026-10-02T03:00:00Z', '2026-10-03T03:00:00Z']], $this->availabilityAsked);
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

    // ---- timezone of the plan ----

    /** The plan names the zone of the account and reads the times of an event without a zone in it, not in UTC. */
    public function testThePlanNamesTheAccountZoneAndReadsAZonelessEventInIt(): void {
        $this->seedEvent();
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T12:30:00Z', 'end' => '2026-10-01T13:30:00Z'];
        $plan = self::json($this->registry->call('calendar_update_event', $args, 'alice'));
        self::assertSame('America/Sao_Paulo', $plan['timezone']);
        $text = $this->registry->call('calendar_update_event', $args, 'alice')['content'][0]['text'];
        self::assertStringContainsString('09:00–10:00 → qui, 1 de out. de 2026, 09:30–10:30', $text);
        self::assertStringNotContainsString('12:00', $text);
    }

    /** The collision warning of an event without a zone is written in the zone of the account too. */
    public function testACollisionOfAZonelessEventIsWrittenInTheAccountZone(): void {
        $this->store->addObject(1, 'dentist.ics', self::ics("UID:dentist\nSUMMARY:Dentista\nDTSTART:20261001T173000Z\nDTEND:20261001T183000Z"));
        $plan = self::json($this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Planejamento', 'start' => '2026-10-01T14:00:00-03:00', 'end' => '2026-10-01T15:00:00-03:00'], 'alice'));
        self::assertSame([['type' => 'collision', 'message' => 'Sobrepõe Dentista (qui, 1 de out. de 2026, 14:30–15:30)']], $plan['warnings']);
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
