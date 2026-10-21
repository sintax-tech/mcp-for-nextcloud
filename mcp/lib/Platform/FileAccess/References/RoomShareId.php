<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\FileAccess\References;

/** Native room-share provider identity, shared by attachment input and output checks. */
final class RoomShareId {
    public static function full(int|string $id): string {
        $id = (string)$id;
        return str_contains($id, ':') ? $id : 'ocRoomShare:' . $id;
    }
}
