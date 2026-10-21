<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess;

/** Reserved backup location shared by file access policies and feature backups. */
final class BackupLocation {
    public const FOLDER = 'MCP backups';

    public static function isBackupPath(string $path): bool {
        return $path === '/' . self::FOLDER || str_starts_with($path, '/' . self::FOLDER . '/');
    }
}
