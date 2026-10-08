<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use RuntimeException;

final class ListCalendarsTest extends CalendarTestCase {
    public function testListsVisibleEventCalendarsWithOwnerAndWriteAccess(): void {
        $list = self::json($this->call('calendar_list_calendars'));
        $this->assertSame([
            ['name' => 'Pessoal', 'path' => self::PERSONAL, 'owner' => 'alice', 'writable' => true],
            ['name' => 'Trabalho', 'path' => self::WORK, 'owner' => 'alice', 'writable' => true],
            ['name' => 'Equipe (bob)', 'path' => self::TEAM, 'owner' => 'bob', 'writable' => true],
            ['name' => 'private_shared_by_bob', 'path' => self::READONLY, 'owner' => 'bob', 'writable' => false],
            ['name' => 'contact_birthdays', 'path' => self::BIRTHDAYS, 'owner' => 'alice', 'writable' => false],
        ], $list);
    }

    public function testOnlyTheAuthenticatedPrincipalIsQueried(): void {
        $this->assertSame([], self::json($this->module->call('calendar_list_calendars', [], 'mallory')));
    }

    public function testPathEncodesUserAndCalendarUri(): void {
        $this->store->addCalendar('principals/users/a b', 9, 'x y', 'principals/users/a b');
        $list = self::json($this->module->call('calendar_list_calendars', [], 'a b'));
        $this->assertSame('/remote.php/dav/calendars/a%20b/x%20y/', $list[0]['path']);
    }

    public function testBackendFailurePropagatesAsNonArgumentError(): void {
        $this->store->failure = new RuntimeException('db down: secret');
        $this->expectException(RuntimeException::class);
        $this->call('calendar_list_calendars');
    }

    public function testLibraryInvalidArgumentIsNotReportedAsInvalidParams(): void {
        $this->store->failure = new \InvalidArgumentException('internal detail');
        try {
            $this->call('calendar_list_calendars');
            $this->fail('expected exception');
        } catch (\InvalidArgumentException) {
            $this->fail('library InvalidArgumentException must not become -32602');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('internal detail', $e->getMessage());
        }
    }
}
