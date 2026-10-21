<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Contracts;

interface NoteResourceReader
{

    /** @return array{contents: list<array{uri:string, mimeType:string, text:string}>} */

    public function read(string $uri, string $userId): array;
}
