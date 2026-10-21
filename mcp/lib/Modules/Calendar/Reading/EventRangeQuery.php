<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Reading;

use DateTimeImmutable;
interface EventRangeQuery
{
    public function eventUrisInRange(
        int $calendarId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
