<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\MailCards;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The card step of a mail card: one Deck card whose description carries the reference to the saved file and to the
 * message. Implemented by the Deck module through the work classes of deck_create_card; without a binding the
 * container fails.
 *
 * Every call checks again, for the actor, everything deck_create_card is checked for: the grant deck.create, Guests,
 * the Deck app, the arguments, the board ACL of the stack and of each assignee, and `confirm_shared` for a board of
 * somebody else. The card is created as deck_create_card creates it: no file is attached and nothing is shared. A
 * plan writes nothing; a step runs at most once.
 */
interface DeckCardCreate {
	/**
	 * The plan of deck_create_card, with the board, its owner and whether it is shared.
	 *
	 * @param string $userId the actor, owner of the new card
	 * @param array<string, mixed> $card the arguments of deck_create_card, without confirm
	 * @return array<string, mixed> the plan of deck_create_card, with nothing written
	 * @throws ToolFailure when the step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on arguments deck_create_card refuses
	 */
	public function planCard(string $userId, array $card): array;

	/**
	 * Creates the card as deck_create_card does, once.
	 *
	 * @param string $userId the actor, owner of the new card
	 * @param array<string, mixed> $card the arguments of deck_create_card, without confirm
	 * @return MailCardStep applied, partial (assignees failed, the card exists), failed (no card) or uncertain (the card may exist)
	 * @throws \InvalidArgumentException on arguments deck_create_card refuses
	 */
	public function createCard(string $userId, array $card): MailCardStep;
}
