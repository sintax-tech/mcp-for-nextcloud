<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The Deck read of a meeting dossier. Implemented by the Deck module through deck_read_card and
 * deck_list_card_files, so assignments and explicit file links stay those readers' own rules. An absent binding
 * omits Deck from the dossier; it does not fail the container. Neither call writes.
 *
 * Each call checks again, for the actor, everything that read tool is checked for: the grant of its operation,
 * Guests and the Deck app.
 */
interface MeetingDeckRead {
	/**
	 * The card deck_read_card would return.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of deck_read_card
	 * @return array<string, mixed> the result of deck_read_card
	 * @throws ToolFailure when the actor may not call deck_read_card
	 * @throws \InvalidArgumentException on arguments deck_read_card refuses
	 */
	public function card(string $userId, array $arguments): array;

	/**
	 * The file links deck_list_card_files would return.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of deck_list_card_files
	 * @return array<string, mixed> the result of deck_list_card_files
	 * @throws ToolFailure when the actor may not call deck_list_card_files
	 * @throws \InvalidArgumentException on arguments deck_list_card_files refuses
	 */
	public function links(string $userId, array $arguments): array;
}
