<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\NativeCalendarsAdapter;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use PHPUnit\Framework\TestCase;

/** The Access adapter preserves ordering, identity, display labels, permissions and native failures. */
final class NativeCalendarsAdapterTest extends TestCase {
	/** @return void Only the three Access fields cross the boundary, in native order. */
	public function testNativeFieldsAndOrderArePreserved(): void {
		$native = $this->createMock(CalendarAccess::class);
		$native->expects(self::once())->method('visible')->with('alice', 'VTODO')->willReturn([
			new Calendar(9, 'a', 'Read only', 'bob', 'principals/users/bob', false, '/a/'),
			new Calendar(2, 'b', 'Writable', 'alice', 'principals/users/alice', true, '/b/'),
		]);
		$rows = (new NativeCalendarsAdapter($native))->visible('alice', 'VTODO');
		self::assertSame([['id' => 9, 'name' => 'Read only', 'writable' => false], ['id' => 2, 'name' => 'Writable', 'writable' => true]], array_map(get_object_vars(...), $rows));
	}

	/** @return void Unavailable native calendars cannot become a successful empty enumeration. */
	public function testNativeFailurePropagatesUnchanged(): void {
		$failure = new \RuntimeException('native unavailable');
		$native = $this->createMock(CalendarAccess::class);
		$native->method('visible')->willThrowException($failure);
		$this->expectExceptionObject($failure);
		(new NativeCalendarsAdapter($native))->visible('alice', 'VEVENT');
	}
}
