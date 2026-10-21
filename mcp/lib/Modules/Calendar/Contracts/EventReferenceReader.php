<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Contracts;

interface EventReferenceReader
{
    /** @return array{title:string, url:string} @throws EventReferenceNotFound */
    public function read(
        string $userId,
        string $calendarPath,
        string $uid,
    ): array;
}
