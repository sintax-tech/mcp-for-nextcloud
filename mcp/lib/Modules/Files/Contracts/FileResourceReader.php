<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Contracts;

/** Files resources retain their own envelope and size limits, independently of tools. */
interface FileResourceReader {
    public const MAX_TEXT_CHARS = 100000;
    public const MAX_BLOB_BYTES = 524288;

    public function read(string $uri, string $userId): array;
}
