<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * A calendar as seen by one user: backend identity, owner and effective write access.
 */
final class Calendar {
    /**
     * @param int $id backend calendar id
     * @param string $uri URI in the user's calendar home (shared ones end in "_shared_by_<owner>")
     * @param string $name display name
     * @param string $ownerId UID of the owner
     * @param string $ownerPrincipal owner principal, e.g. "principals/users/bob"
     * @param bool $writable whether the viewing user may change its events
     * @param string $path DAV path used by the tools, "/remote.php/dav/calendars/<uid>/<uri>/"
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uri,
        public readonly string $name,
        public readonly string $ownerId,
        public readonly string $ownerPrincipal,
        public readonly bool $writable,
        public readonly string $path,
    ) {}

    /**
     * @param string $userId viewing user
     * @return bool whether the calendar belongs to someone else
     */
    public function ownedByOther(string $userId): bool {
        return $this->ownerId !== $userId;
    }
}
