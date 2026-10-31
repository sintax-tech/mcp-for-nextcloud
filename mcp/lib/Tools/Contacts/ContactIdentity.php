<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use Sabre\VObject\Component\VCard;

/**
 * Builds the vCard of a contact that does not exist yet.
 *
 * The UID is the one the creation plan already showed. It is not drawn here.
 */
final class ContactIdentity {
	/**
	 * @param ContactCard $cards field patch that preserves parameters the new card does not have yet
	 * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields stay empty
	 * @param string $uid contact UID chosen once for the plan and the confirmed write
	 * @return VCard card with that UID and the supplied fields applied
	 */
	public function card(ContactCard $cards, array $arguments, string $uid): VCard {
		$card = new VCard(
			[
				'VERSION' => '3.0',
				'UID' => $uid,
				'FN' => $arguments['name'],
			]
		);

		return $cards->patch($card, $arguments);
	}
}
