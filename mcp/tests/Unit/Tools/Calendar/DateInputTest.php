<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\DateInput;
use PHPUnit\Framework\TestCase;

final class DateInputTest extends TestCase {
    public function testAcceptsPrototypeFormatsAndNormalisesToUtc(): void {
        $dates = new DateInput();
        $this->assertSame('2026-03-10T00:00:00+00:00', $dates->parse('2026-03-10', 'from')->format('c'));
        $this->assertSame('2026-03-10T13:30:00+00:00', $dates->parse('2026-03-10T10:30-03:00', 'from')->format('c'));
        $this->assertSame('2026-03-10T10:30:05+00:00', $dates->parse('2026-03-10t10:30:05.123z', 'from')->format('c'));
    }

    public function testRejectsInvalidOffset(): void {
        $this->expectException(CalendarArgumentException::class);
        $this->expectExceptionMessage('Invalid ISO date in to.');
        (new DateInput())->parse('2026-03-10T10:30:00+25:00', 'to');
    }

    public function testWindowDefaultsAndLimit(): void {
        $dates = new DateInput();
        [$from, $to] = $dates->window(null, null, new DateTimeImmutable('2026-03-10T12:00:00-03:00'));
        $this->assertSame(['2026-03-10T15:00:00+00:00', '2026-03-17T15:00:00+00:00'], [$from->format('c'), $to->format('c')]);
        [, $to] = $dates->window('2026-03-10', null, new DateTimeImmutable());
        $this->assertSame('2026-03-17', $to->format('Y-m-d'));
        $this->expectException(CalendarException::class);
        $dates->window('2026-01-01T00:00:00Z', '2027-01-02T00:00:01Z', new DateTimeImmutable());
    }
}
