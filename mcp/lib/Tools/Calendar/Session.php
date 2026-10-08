<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\IUserSession;

/**
 * The UID the request runs as, read from the server session.
 *
 * The DAV plugins read the sender of a scheduling message from the session
 * (nextcloud/server stable33, apps/dav/lib/CalDAV/Schedule/IMipPlugin.php:266-273), so a write that
 * pins a principal different from the session would schedule a message with the wrong sender. The
 * dispatcher refuses to dispatch at all when the two disagree.
 */
final class Session {
    /**
     * @param IUserSession $session Nextcloud session
     */
    public function __construct(private IUserSession $session) {}

    /**
     * @return string|null UID of the active user, or null when there is no session
     */
    public function uid(): ?string {
        return $this->session->getUser()?->getUID();
    }
}