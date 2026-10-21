<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\Icalendar;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\L10n\Translator;
final class IsoDateInput
{
    /** Date without time: "YYYY-MM-DD". */
    private const DATE_ONLY = '/^(\d{4})-(\d{2})-(\d{2})$/';
    /** Timestamp with mandatory "Z" or offset; seconds and milliseconds optional. */
    private const TIMESTAMP = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,3})?)?(Z|[+-]\d{2}:\d{2})$/i';
    public function isDateOnly(string $input): bool
    {
        return preg_match(self::DATE_ONLY, $input) === 1;
    }
    public function parse(string $input, string $label): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        if (preg_match(self::DATE_ONLY, $input, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return new DateTimeImmutable($input . 'T00:00:00', $utc);
        }
        if (preg_match(self::TIMESTAMP, $input, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) ($m[6] ?? 0) < 60) {
            $offset = strtoupper($m[7]) === 'Z' ? '+00:00' : $m[7];
            if ((int) substr($offset, 1, 2) < 24 && (int) substr($offset, 4, 2) < 60) {
                return (new DateTimeImmutable($input))->setTimezone($utc);
            }
        }
        throw new DateArgumentException(Translator::t('Invalid ISO date in %s.', [$label]), $label, Translator::t('expected ISO date or date-time with Z or offset'));
    }
}
