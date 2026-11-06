<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

use OCA\Mcp\L10n\Translator;

/**
 * Caller-safe wording for the strict native Deck file link contract.
 */
final class CardLinkMessages {
	/**
	 * Explain policy refusal without exposing board participants or administrator values.
	 *
	 * @return string Localized strict-policy refusal.
	 */
	public static function refused(): string {
		return Translator::t('This file cannot be linked under the current sharing rules. The link is refused for a Guest, for group-only sharing, when group sharing is off and the board includes a group or a team, when internal expiry is mandatory, when sharing or resharing is off, or when a recipient is not a fully resolved local user, group or team. The same answer is used when those facts cannot be read. Nothing here asks you to change a setting or to sign in another way.');
	}

	/**
	 * Explain a duplicate only after both the source and file were authorized.
	 *
	 * @return string Localized duplicate refusal.
	 */
	public static function duplicate(): string {
		return Translator::t('This file is already linked to this card. No new link was created.');
	}

	/**
	 * Describe current and future access without promising continuous enforcement.
	 *
	 * @return string Localized mandatory plan warning.
	 */
	public static function warning(): string {
		return Translator::t('Linking grants read-only access (READ=1) to all current board members shown in this plan. Future board, group and team members can also read the file. Unlinking revokes this link for everyone; independent shares may keep access. A policy enabled after the link, and a later change of board, group or team membership, are not corrected and the link is not revoked automatically. The check is not atomic: access can still change between the last check and the native share. Hashes do not close that gap, and the server does not attest human consent.');
	}

	/**
	 * Explain an unverified outcome without inviting automatic recreation.
	 *
	 * @return string Localized uncertain-result instruction.
	 */
	public static function uncertain(): string {
		return Translator::t('The file link outcome is uncertain. Inspect the card in Deck before retrying; a visible association alone does not prove which request created it.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/**
	 * Clarify the native listing boundary without suggesting hidden shares exist or reporting their count.
	 *
	 * @return string Localized scope of the independent-share preview.
	 */
	public static function shareScope(): string {
		return Translator::t('Only user, group and public-link shares you created are shown. Other shares are not enumerated.');
	}
}
