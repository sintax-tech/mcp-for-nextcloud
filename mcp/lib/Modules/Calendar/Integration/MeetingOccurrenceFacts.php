<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Integration;

/**
 * Adds attendees and explicit links onto a calendar_list_events answer.
 *
 * The public tool stays as the reader published it. Only a meeting dossier asks for this.
 */
interface MeetingOccurrenceFacts {
	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments calendar_list_events already accepted
	 * @param array<string, mixed> $result the answer of calendar_list_events
	 * @return array<string, mixed> that answer, with attendees and links on full occurrences
	 */
	public function apply(string $userId, array $arguments, array $result): array;
}
