<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

use OCA\Mcp\Platform\Dav\Transport\DavResult;
interface CalendarAclProbe
{
    public function probeWrite(
        string $actorUid,
        string $ownerUid,
        string $calendarUri,
        string $objectUri,
        string $ics,
    ): DavResult;
}
