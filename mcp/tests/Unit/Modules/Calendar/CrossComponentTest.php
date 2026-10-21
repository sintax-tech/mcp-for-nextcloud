<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A calendar holding both VEVENT and VTODO. The event tools must never reach the task by its UID: every plan and every
 * confirmed delete, update, move and transfer answers exactly what an unknown UID answers, and nothing is written.
 */
final class CrossComponentTest extends CalendarTestCase {
    private const TODO = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:todo1\r\nDTSTAMP:20261001T100000Z\r\n"
        . "SUMMARY:A task\r\nDTSTART:20260312T090000Z\r\nDUE:20260312T100000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

    protected function setUp(): void {
        parent::setUp();
        // personal (1) and work (2) are alice's; team (3) is bob's, shared with her. Each holds VEVENT and VTODO.
        $this->store->addObject(1, 'todo1.ics', self::TODO);
    }

    /** @return array<string, array{string, array<string, mixed>}> each names the object through `{uid}` */
    public static function calls(): array {
        $base = ['calendar' => self::PERSONAL, 'uid' => '{uid}'];
        return [
            'delete plan' => ['calendar_delete_event', $base],
            'delete confirmed' => ['calendar_delete_event', $base + ['confirm' => true]],
            'update plan' => ['calendar_update_event', $base + ['summary' => 'Changed']],
            'update confirmed' => ['calendar_update_event', $base + ['summary' => 'Changed', 'confirm' => true]],
            'move plan' => ['calendar_move_event', $base + ['targetCalendar' => self::WORK]],
            'move confirmed' => ['calendar_move_event', $base + ['targetCalendar' => self::WORK, 'confirm' => true]],
            'transfer plan' => ['calendar_transfer_event', $base + ['targetCalendar' => self::TEAM]],
            'transfer confirmed' => ['calendar_transfer_event', $base + ['targetCalendar' => self::TEAM, 'confirm' => true, 'confirm_shared' => true]],
        ];
    }

    #[DataProvider('calls')]
    public function testAnEventToolAnswersATaskUidExactlyLikeAnUnknownOne(string $name, array $arguments): void {
        $named = static fn (string $uid): array => array_map(static fn ($value) => $value === '{uid}' ? $uid : $value, $arguments);
        $task = $this->registry->call($name, $named('todo1'), 'alice');
        $missing = $this->registry->call($name, $named('no-such-uid'), 'alice');

        $this->assertTrue($task['isError'] ?? false, json_encode($task));
        $this->assertSame($missing, $task);
        $this->assertNoWrites();
        $this->assertArrayHasKey('todo1.ics', $this->store->objects[1], 'the task stays where it is');
    }
}
