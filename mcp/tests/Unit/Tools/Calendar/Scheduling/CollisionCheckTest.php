<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\EventExpander;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\Scheduling\CollisionCheck;
use Psr\Log\LoggerInterface;

final class CollisionCheckTest extends CalendarTestCase {
    private CollisionCheck $checker;
    private Calendar $personal;
    private Calendar $team;

    protected function setUp(): void {
        parent::setUp();
        $classification = new Classification();
        $repository = new EventRepository($this->store, $classification);
        $expander = new EventExpander();
        $logger = $this->createMock(LoggerInterface::class);
        $this->checker = new CollisionCheck($this->store, $repository, $classification, $expander, $logger);

        $this->personal = new Calendar(1, 'personal', 'Pessoal', 'alice', self::ALICE, true, self::PERSONAL);
        $this->team = new Calendar(3, 'team_shared_by_bob', 'Equipe (bob)', 'bob', self::BOB, true, self::TEAM);
    }

    public function testNoCollisionReturnsEmptyListAndZeroMore(): void {
        $this->store->addObject(1, 'other.ics', self::ics("UID:other\nSUMMARY:Outro\nDTSTART:20260310T080000Z\nDTEND:20260310T090000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertSame(['items' => [], 'more' => 0], $result);
    }

    public function testSingleCollisionReturnsItemWithMoreZero(): void {
        $this->store->addObject(1, 'meeting.ics', self::ics("UID:m1\nSUMMARY:Reunião de equipe\nDTSTART:20260310T103000Z\nDTEND:20260310T113000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertSame(0, $result['more']);
        $this->assertCount(1, $result['items']);
        $this->assertSame([
            'uri' => 'meeting.ics',
            'summary' => 'Reunião de equipe',
            'start' => '2026-03-10T10:30:00Z',
            'end' => '2026-03-10T11:30:00Z',
            'allDay' => false,
            'busyOnly' => false,
        ], $result['items'][0]);
    }

    public function testSixCollisionsReturnsFiveItemsAndMoreOneSortedByStart(): void {
        // Add 6 events overlapping [2026-03-10T08:00:00Z, 2026-03-10T18:00:00Z) in reverse chronological order
        for ($i = 6; $i >= 1; $i--) {
            $hour = sprintf('%02d', 8 + $i);
            $nextHour = sprintf('%02d', 9 + $i);
            $this->store->addObject(
                1,
                "event$i.ics",
                self::ics("UID:ev$i\nSUMMARY:Event $i\nDTSTART:20260310T{$hour}0000Z\nDTEND:20260310T{$nextHour}0000Z"),
            );
        }

        $start = new DateTimeImmutable('2026-03-10T08:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T18:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertSame(1, $result['more']);
        $this->assertCount(5, $result['items']);

        // Verify ordering: Event 1 (09:00), Event 2 (10:00), Event 3 (11:00), Event 4 (12:00), Event 5 (13:00)
        $expectedSummaries = ['Event 1', 'Event 2', 'Event 3', 'Event 4', 'Event 5'];
        $this->assertSame($expectedSummaries, array_column($result['items'], 'summary'));
        $this->assertSame('2026-03-10T09:00:00Z', $result['items'][0]['start']);
        $this->assertSame('2026-03-10T13:00:00Z', $result['items'][4]['start']);
    }

    public function testCustomLimitSlicesProperlyAndReportsRemainingCount(): void {
        for ($i = 1; $i <= 4; $i++) {
            $hour = sprintf('%02d', 8 + $i);
            $nextHour = sprintf('%02d', 9 + $i);
            $this->store->addObject(
                1,
                "item$i.ics",
                self::ics("UID:it$i\nSUMMARY:Item $i\nDTSTART:20260310T{$hour}0000Z\nDTEND:20260310T{$nextHour}0000Z"),
            );
        }

        $start = new DateTimeImmutable('2026-03-10T08:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T18:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false, null, 2);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['more']);
        $this->assertSame(['Item 1', 'Item 2'], array_column($result['items'], 'summary'));
    }

    public function testBusyEventOfOtherOwnerHidesSummaryAndSetsBusyOnly(): void {
        // Event in bob's shared calendar with CLASS:CONFIDENTIAL
        $this->store->addObject(3, 'confidential.ics', self::ics("UID:conf\nCLASS:CONFIDENTIAL\nSUMMARY:Médico\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->team, 'alice', $start, $end, false);

        $this->assertSame(0, $result['more']);
        $this->assertCount(1, $result['items']);
        $this->assertSame([
            'uri' => 'confidential.ics',
            'summary' => null,
            'start' => '2026-03-10T10:00:00Z',
            'end' => '2026-03-10T11:00:00Z',
            'allDay' => false,
            'busyOnly' => true,
        ], $result['items'][0]);
    }

    public function testConfidentialEventInOwnCalendarIsNotMasked(): void {
        // Alice's own event with CLASS:CONFIDENTIAL
        $this->store->addObject(1, 'own_conf.ics', self::ics("UID:own\nCLASS:CONFIDENTIAL\nSUMMARY:Consulta\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertCount(1, $result['items']);
        $this->assertSame('Consulta', $result['items'][0]['summary']);
        $this->assertFalse($result['items'][0]['busyOnly']);
    }

    public function testHiddenEventOfOtherOwnerIsIgnored(): void {
        // Event in bob's shared calendar with CLASS:PRIVATE
        $this->store->addObject(3, 'private.ics', self::ics("UID:priv\nCLASS:PRIVATE\nSUMMARY:Segredo\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->team, 'alice', $start, $end, false);

        $this->assertSame(['items' => [], 'more' => 0], $result);
    }

    public function testTransparentEventDoesNotCollide(): void {
        $this->store->addObject(1, 'free.ics', self::ics("UID:free\nTRANSP:TRANSPARENT\nSUMMARY:Lembrete\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertSame(['items' => [], 'more' => 0], $result);
    }

    public function testTransparentOverrideIgnoresSpecificOccurrence(): void {
        // Master is OPAQUE, override on 2026-03-11 is TRANSPARENT
        $master = "UID:rtransp\nTRANSP:OPAQUE\nSUMMARY:Série\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z\nRRULE:FREQ=DAILY;COUNT=3";
        $freeOverride = "BEGIN:VEVENT\nUID:rtransp\nRECURRENCE-ID:20260311T100000Z\nTRANSP:TRANSPARENT\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z\nEND:VEVENT";
        $this->store->addObject(1, 'rtransp.ics', self::ics($master, $freeOverride));

        $start = new DateTimeImmutable('2026-03-10T00:00:00Z');
        $end = new DateTimeImmutable('2026-03-13T00:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        // March 10 and March 12 collide; March 11 is transparent
        $this->assertCount(2, $result['items']);
        $this->assertSame('2026-03-10T10:00:00Z', $result['items'][0]['start']);
        $this->assertSame('2026-03-12T10:00:00Z', $result['items'][1]['start']);
    }

    public function testAllDayEventSearchesEntireDayInEventTimezone(): void {
        // Existing timed event in personal calendar: 14:00 to 15:00 UTC
        $this->store->addObject(1, 'afternoon.ics', self::ics("UID:aft\nSUMMARY:Tarde\nDTSTART:20260310T140000Z\nDTEND:20260310T150000Z"));

        // Searching an all-day event for 2026-03-10 in America/Sao_Paulo (UTC-3: 2026-03-10 03:00:00Z to 2026-03-11 03:00:00Z)
        $tz = new DateTimeZone('America/Sao_Paulo');
        $start = new DateTimeImmutable('2026-03-10 00:00:00', $tz);
        $end = new DateTimeImmutable('2026-03-11 00:00:00', $tz);

        $result = $this->checker->find($this->personal, 'alice', $start, $end, true);

        $this->assertCount(1, $result['items']);
        $this->assertSame('afternoon.ics', $result['items'][0]['uri']);
        $this->assertSame('Tarde', $result['items'][0]['summary']);

        // An event before midnight in Sao Paulo (2026-03-10 02:00:00Z = 2026-03-09 23:00:00 in Sao Paulo) should NOT collide
        $this->store->addObject(1, 'early.ics', self::ics("UID:early\nSUMMARY:Cedo\nDTSTART:20260310T010000Z\nDTEND:20260310T020000Z"));

        $result2 = $this->checker->find($this->personal, 'alice', $start, $end, true);
        $this->assertCount(1, $result2['items']);
        $this->assertSame('afternoon.ics', $result2['items'][0]['uri']);
    }

    public function testExistingAllDayEventCollidesWithTimedEvent(): void {
        // Existing all-day event in personal calendar
        $this->store->addObject(1, 'holiday.ics', self::ics("UID:hol\nSUMMARY:Feriado\nDTSTART;VALUE=DATE:20260310\nDTEND;VALUE=DATE:20260311"));

        $start = new DateTimeImmutable('2026-03-10T14:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T15:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertCount(1, $result['items']);
        $this->assertSame('holiday.ics', $result['items'][0]['uri']);
        $this->assertSame('Feriado', $result['items'][0]['summary']);
        $this->assertTrue($result['items'][0]['allDay']);
    }

    public function testHalfOpenIntervalBoundaryDoesNotCollide(): void {
        // Event exactly ending at 10:00
        $this->store->addObject(1, 'before.ics', self::ics("UID:bef\nSUMMARY:Antes\nDTSTART:20260310T090000Z\nDTEND:20260310T100000Z"));
        // Event exactly starting at 11:00
        $this->store->addObject(1, 'after.ics', self::ics("UID:aft2\nSUMMARY:Depois\nDTSTART:20260310T110000Z\nDTEND:20260310T120000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertSame(['items' => [], 'more' => 0], $result);
    }

    public function testEventWithoutSummaryReturnsNullSummary(): void {
        $this->store->addObject(1, 'nosummary.ics', self::ics("UID:nosum\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertCount(1, $result['items']);
        $this->assertNull($result['items'][0]['summary']);
        $this->assertFalse($result['items'][0]['busyOnly']);
    }

    public function testExcludeUriOmitsTheEventItself(): void {
        $this->store->addObject(1, 'current.ics', self::ics("UID:curr\nSUMMARY:Minha reunião\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        // Without excludeUri, it collides
        $resultWith = $this->checker->find($this->personal, 'alice', $start, $end, false);
        $this->assertCount(1, $resultWith['items']);

        // With excludeUri, it is excluded
        $resultExcluded = $this->checker->find($this->personal, 'alice', $start, $end, false, 'current.ics');
        $this->assertSame(['items' => [], 'more' => 0], $resultExcluded);
    }

    public function testRecurringEventCountsByOccurrence(): void {
        // Daily recurring event: 2026-03-10, 2026-03-11, 2026-03-12 (all at 10:00-11:00Z)
        // With an override on 2026-03-11 that is CANCELLED
        $master = "UID:daily\nSUMMARY:Diária\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z\nRRULE:FREQ=DAILY;COUNT=3";
        $cancelledOverride = "BEGIN:VEVENT\nUID:daily\nRECURRENCE-ID:20260311T100000Z\nSTATUS:CANCELLED\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z\nEND:VEVENT";
        $this->store->addObject(1, 'daily.ics', self::ics($master, $cancelledOverride));

        // Window covering all 3 days
        $start = new DateTimeImmutable('2026-03-10T00:00:00Z');
        $end = new DateTimeImmutable('2026-03-13T00:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        // March 10 and March 12 occurrences should be returned; March 11 is cancelled
        $this->assertCount(2, $result['items']);
        $this->assertSame(0, $result['more']);
        $this->assertSame('2026-03-10T10:00:00Z', $result['items'][0]['start']);
        $this->assertSame('2026-03-12T10:00:00Z', $result['items'][1]['start']);
        $this->assertSame('daily.ics', $result['items'][0]['uri']);
        $this->assertSame('daily.ics', $result['items'][1]['uri']);
    }

    public function testInvalidObjectIsSkipped(): void {
        $this->store->addObject(1, 'corrupted.ics', 'THIS IS NOT VALID ICALENDAR');
        $this->store->addObject(1, 'valid.ics', self::ics("UID:valid\nSUMMARY:Válido\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z"));

        $start = new DateTimeImmutable('2026-03-10T10:00:00Z');
        $end = new DateTimeImmutable('2026-03-10T11:00:00Z');

        $result = $this->checker->find($this->personal, 'alice', $start, $end, false);

        $this->assertCount(1, $result['items']);
        $this->assertSame('valid.ics', $result['items'][0]['uri']);
    }
}
