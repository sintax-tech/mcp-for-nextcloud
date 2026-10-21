<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Dav;
use OCA\Mcp\Platform\Dav\Icalendar\IsoDateInput;
use OCA\Mcp\Platform\Dav\Icalendar\DateArgumentException;
use PHPUnit\Framework\TestCase;
final class IsoDateInputTest extends TestCase {
    public function testUtcConversionDateOnlyAndInvalidCalendarDateKeepTheirContract(): void {
        \OCA\Mcp\L10n\Translator::reset();
        $dates = new IsoDateInput();
        self::assertSame('2026-10-01T12:30:00+00:00', $dates->parse('2026-10-01T14:30:00+02:00', 'due')->format(DATE_ATOM));
        self::assertSame('2026-10-01T00:00:00+00:00', $dates->parse('2026-10-01', 'due')->format(DATE_ATOM));
        foreach (['2026-02-30','2026-10-01T12:00:00','2026-10-01T24:00:00Z'] as $invalid) {
            try { $dates->parse($invalid, 'due'); self::fail('Invalid date accepted'); }
            catch (DateArgumentException $e) { self::assertSame(['field'=>'due','rule'=>'expected ISO date or date-time with Z or offset'], $e->details()); }
        }
    }
}
