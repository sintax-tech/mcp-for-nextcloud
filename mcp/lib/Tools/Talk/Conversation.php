<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

/**
 * A room already resolved for the authenticated user, together with that user's participant.
 *
 * Both are OCA\Talk objects held untyped on purpose: the class may not exist in this installation and the module
 * must compile and be testable without it.
 */
final class Conversation {
    public function __construct(
        public readonly object $room,
        public readonly object $participant,
    ) {}

    /** Public token of the room, the only conversation identifier the user ever sees. */
    public function token(): string {
        return (string)$this->room->getToken();
    }

    /**
     * Name the user knows this conversation by: Talk resolves the display name per participant, so a one to one
     * shows the contact name and a group its own name. A draft has to name the same conversation the message
     * will land in, otherwise approval means nothing.
     *
     * @param string $userId Authenticated participant
     */
    public function displayName(string $userId): string {
        return (string)$this->room->getDisplayName($userId);
    }
}
