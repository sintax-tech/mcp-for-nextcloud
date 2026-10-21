<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Tools;
final class DavCollectionSchema {
    public static function calendar(string $description = 'calendar path, as returned by calendar_list_calendars'): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'description' => $description];
    }
    public static function uid(): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'event UID'];
    }
    public static function etag(): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'expected etag; if it differs, nothing is changed'];
    }
    public static function text(string $description, int $minLength, int $maxLength): array {
        return ['type' => 'string', 'minLength' => $minLength, 'maxLength' => $maxLength, 'description' => $description];
    }
    public static function offset(): array {
        return [
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 1000000,
            'default' => 0,
            'description' => 'Offset among matching results; use nextOffset to continue.',
        ];
    }
    public static function limit(): array {
        return [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 200,
            'default' => 50,
            'description' => 'Maximum results (1-200).',
        ];
    }
}
