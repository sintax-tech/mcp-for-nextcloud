<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

/**
 * Card ids Deck has assigned to one local account.
 *
 * This is not a search by title, and it does not apply the board ACL. The card read does that afterwards.
 */
interface AssignedCardLookup {
	/**
	 * @param string $userId the actor, bound before Deck runs
	 * @param string $participantUid the local account
	 * @return list<int> card ids, once each
	 */
	public function ids(string $userId, string $participantUid): array;
}
