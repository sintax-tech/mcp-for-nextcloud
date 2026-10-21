<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

interface ObjectStore
{
    public function objects(int $calendarId, array $uris): array;
    public function object(int $calendarId, string $uri): ?array;
    public function objectByUid(int $calendarId, string $uid, string $component): ?array;
}
