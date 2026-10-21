<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Access;

use OCA\Mcp\Modules\Deck\Execution\WriteMessages;

class SharedBoardConfirmation {
    /**
     * Shared-resource gate every write tool runs before touching the Deck.
     *
     * A board owned by somebody else is written only when the call repeats with
     * `confirm_shared: true`; otherwise the payload below is returned as a normal, non-error
     * result, so the agent can read it, ask the user and call the tool again. The caller's own
     * board never needs the flag.
     *
     * @param array<string, mixed> $arguments Arguments of the call, read for `confirm_shared`.
     * @param string $userId UID of the authenticated caller.
     * @param array{owner: string, ownerDisplayName: string, name: string} $ownership Board the write would touch.
     * @return array<string, mixed>|null Payload to answer with instead of writing, or null to proceed.
     */
    public static function confirmShared(
        array $arguments,
        string $userId,
        array $ownership,
    ): ?array {
        if ($ownership['owner'] === $userId || ($arguments['confirm_shared'] ?? null) === true) {
            return null;
        }
        return [
            'requiresConfirmation' => true,
            'scope' => 'shared',
            'owner' => $ownership['owner'],
            'ownerDisplayName' => $ownership['ownerDisplayName'],
            'resource' => $ownership['name'],
            'message' => WriteMessages::sharedConfirmation($ownership['name'], $ownership['ownerDisplayName']),
        ];
    }
}
