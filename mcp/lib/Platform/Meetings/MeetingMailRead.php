<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The mail read of a meeting dossier. Implemented by the Mail module through mail_search_messages only: the search
 * does not fetch a body and does not change flags. An absent binding omits mail from the dossier; it does not fail
 * the container.
 *
 * The call checks again, for the actor, everything mail_search_messages is checked for: the grant of its operation,
 * Guests and the Mail app.
 */
interface MeetingMailRead {
	/**
	 * Messages mail_search_messages would return for these arguments.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of mail_search_messages
	 * @return array<string, mixed> the result of mail_search_messages
	 * @throws ToolFailure when the actor may not call mail_search_messages
	 * @throws \InvalidArgumentException on arguments mail_search_messages refuses
	 */
	public function search(string $userId, array $arguments): array;
}
