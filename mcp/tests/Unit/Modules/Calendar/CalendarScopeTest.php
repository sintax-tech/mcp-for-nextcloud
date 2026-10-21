<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;


use OCA\Mcp\Access\Policy\PolicyUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B6, the calendar scope on every calendar tool, through the registry as a client calls it (contract B.5, S6, S7):
 * a calendar outside the scope is missing from calendar_list_calendars, and every read, plan and confirmed write
 * that names it answers exactly what a calendar that does not exist answers (D6), without writing anything. move and
 * transfer to a target outside the scope are refused the same way. The tasks scope never narrows these tools.
 */
final class CalendarScopeTest extends CalendarTestCase {
    private const MISSING = '/remote.php/dav/calendars/alice/missing/';

    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'p.ics', self::ics("UID:p\nSUMMARY:Inside\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        $this->store->addObject(2, 'w.ics', self::ics("UID:w\nSUMMARY:Outside\nDTSTART:20260312T110000Z\nDTEND:20260312T120000Z"));
        $this->store->addObject(3, 't.ics', self::ics("UID:t\nSUMMARY:Shared\nDTSTART:20260312T130000Z\nDTEND:20260312T140000Z"));
    }

    private function tool(string $name, array $arguments): array {
        return $this->registry->call($name, $arguments, 'alice');
    }

    /** @return list<string> paths calendar_list_calendars returns */
    private function listed(): array {
        return array_column(self::json($this->tool('calendar_list_calendars', [])), 'path');
    }

    /** @return list<string> uids calendar_list_events returns without a calendar */
    private function events(): array {
        $items = self::json($this->tool('calendar_list_events', ['from' => '2026-03-12', 'to' => '2026-03-13']));
        $uids = array_column($items['events'] ?? $items, 'uid');
        sort($uids);
        return $uids;
    }

    /**
     * Each call names one calendar through `{c}` (and the target through `{t}` for move and transfer), so it can be
     * repeated against a calendar outside the scope and against one that does not exist.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function calls(): array {
        $event = ['summary' => 'x', 'start' => '2026-03-12T15:00:00Z', 'end' => '2026-03-12T16:00:00Z'];
        return [
            'list events' => ['calendar_list_events', ['calendar' => '{c}', 'from' => '2026-03-12', 'to' => '2026-03-13']],
            'create plan' => ['calendar_create_event', ['calendar' => '{c}'] + $event],
            'create confirmed' => ['calendar_create_event', ['calendar' => '{c}', 'confirm' => true] + $event],
            'update plan' => ['calendar_update_event', ['calendar' => '{c}', 'uid' => 'w', 'summary' => 'Changed']],
            'update confirmed' => ['calendar_update_event', ['calendar' => '{c}', 'uid' => 'w', 'summary' => 'Changed', 'confirm' => true]],
            'delete plan' => ['calendar_delete_event', ['calendar' => '{c}', 'uid' => 'w']],
            'delete confirmed' => ['calendar_delete_event', ['calendar' => '{c}', 'uid' => 'w', 'confirm' => true]],
            'move from' => ['calendar_move_event', ['calendar' => '{c}', 'uid' => 'w', 'targetCalendar' => self::PERSONAL, 'confirm' => true]],
            'move to' => ['calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'p', 'targetCalendar' => '{c}', 'confirm' => true]],
            'move to, plan' => ['calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'p', 'targetCalendar' => '{c}']],
            'transfer to' => ['calendar_transfer_event', ['calendar' => self::PERSONAL, 'uid' => 'p', 'targetCalendar' => '{c}', 'confirm' => true, 'confirm_shared' => true]],
            'transfer to, plan' => ['calendar_transfer_event', ['calendar' => self::PERSONAL, 'uid' => 'p', 'targetCalendar' => '{c}']],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private static function naming(array $arguments, string $path): array {
        return array_map(static fn ($value) => $value === '{c}' ? $path : $value, $arguments);
    }

    public function testS1WithoutAScopeEveryCalendarStaysReachable(): void {
        $this->assertSame([self::PERSONAL, self::WORK, self::TEAM, self::READONLY, self::BIRTHDAYS], $this->listed());
        $this->assertSame(['p', 't', 'w'], $this->events());
    }

    public function testS7OnlyTheCalendarsInsideTheScopeAreListed(): void {
        // The shared calendar is kept by the owner's calendar id, never by alice's URI of the share.
        $this->scope->restrict('calendar', ['1', '3']);
        $this->assertSame([self::PERSONAL, self::TEAM], $this->listed());
        $this->assertSame(['p', 't'], $this->events());
        // Ids are asked as canonical decimal strings, never as backend integers.
        foreach ($this->scope->asked as [, , $id]) {
            $this->assertIsString($id);
            $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $id);
        }
    }

    #[DataProvider('calls')]
    public function testS7ACalendarOutsideTheScopeAnswersExactlyLikeAMissingOne(string $name, array $arguments): void {
        $this->scope->restrict('calendar', ['1', '3']);
        $outside = $this->tool($name, self::naming($arguments, self::WORK));
        $missing = $this->tool($name, self::naming($arguments, self::MISSING));

        $this->assertTrue($outside['isError'] ?? false, json_encode($outside));
        $this->assertSame($missing, $outside);
        $this->assertNoWrites();
    }

    #[DataProvider('calls')]
    public function testS6AnEmptyScopeReachesNoCalendar(string $name, array $arguments): void {
        $this->scope->restrict('calendar', []);
        $this->assertSame([], $this->listed());
        $this->assertSame([], $this->events());
        $this->assertSame($this->tool($name, self::naming($arguments, self::MISSING)), $this->tool($name, self::naming($arguments, self::PERSONAL)));
        $this->assertNoWrites();
    }

    public function testAReadOnlyCalendarOutsideTheScopeIsMissingNotForbidden(): void {
        $this->scope->restrict('calendar', ['1']);
        $args = ['summary' => 'x', 'start' => '2026-03-12T15:00:00Z', 'end' => '2026-03-12T16:00:00Z'];
        $this->assertSame(
            $this->tool('calendar_create_event', ['calendar' => self::MISSING] + $args),
            $this->tool('calendar_create_event', ['calendar' => self::READONLY] + $args),
        );
    }

    public function testS7TheTasksScopeNeverNarrowsTheCalendarTools(): void {
        $this->scope->restrict('tasks', []);
        $this->assertSame([self::PERSONAL, self::WORK, self::TEAM, self::READONLY, self::BIRTHDAYS], $this->listed());
        $this->assertSame([], array_filter($this->scope->asked, static fn (array $q): bool => $q[1] !== 'calendar'));
    }

    public function testInsideTheScopeAWriteStillWorks(): void {
        $this->scope->restrict('calendar', ['1', '2']);
        $moved = self::json($this->tool('calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'p', 'targetCalendar' => self::WORK, 'confirm' => true]));
        $this->assertSame(self::WORK, $moved['to']);
    }

    /**
     * B6-R1: with the scope unreadable every call answers the same 503, whether the calendar it names exists, is
     * outside the saved scope or does not exist at all, and even when alice has no calendar of the kind.
     */
    #[DataProvider('calls')]
    public function testS11AnUnreadableScopeAnswersAlikeForAnExistingAndAMissingCalendar(string $name, array $arguments): void {
        $this->scope->restrict('calendar', ['1']);
        $this->scope->unavailable = true;
        $outcomes = [];
        foreach ([self::PERSONAL, self::WORK, self::MISSING] as $path) {
            try {
                $this->tool($name, self::naming($arguments, $path));
                $outcomes[] = 'answered';
            } catch (PolicyUnavailable $e) {
                $outcomes[] = $e->getMessage();
            }
        }
        $this->assertSame(array_fill(0, 3, 'Resource scope unavailable'), $outcomes);
        $this->assertNoWrites();
    }

    public function testS11AnUnreadableScopeFailsAUserWithoutAnyCalendarToo(): void {
        $this->store->calendars = [];
        $this->scope->unavailable = true;
        $this->expectException(PolicyUnavailable::class);
        $this->tool('calendar_list_calendars', []);
    }

    public function testS11AnUnreadableScopeFailsTheCallInsteadOfListingEverything(): void {
        $this->scope->unavailable = true;
        foreach ([['calendar_list_calendars', []], ['calendar_list_events', ['from' => '2026-03-12', 'to' => '2026-03-13']],
            ['calendar_list_events', ['calendar' => self::PERSONAL, 'from' => '2026-03-12', 'to' => '2026-03-13']]] as [$name, $arguments]) {
            try {
                $this->tool($name, $arguments);
                $this->fail("$name answered without its scope");
            } catch (PolicyUnavailable) {
            }
        }
        $this->assertNoWrites();
    }
}
