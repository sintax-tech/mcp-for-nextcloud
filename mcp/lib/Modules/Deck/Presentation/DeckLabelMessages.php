<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Descriptions, plans, refusals and warnings of the board label tools and of labels on a new card. */
final class DeckLabelMessages {
	public const TOOL_CREATE_LABEL_DESCRIPTION = 'Creates a label on a Deck board you manage (owner or manage permission), without assigning it to any card. The title (1 to 100 characters) must not match an existing label of the board, ignoring letter case; use deck_list_labels first. Without color, the same title always gets the same colour. Requires deck.edit. Preview first, then confirm: true. To create a card with labels in one step, use deck_create_card with labels.';

	public const TOOL_UPDATE_LABEL_DESCRIPTION = 'Renames or recolours a label of a Deck board you manage; every card carrying it shows the change. Send title, color or both. The new title must not match another label of the board, ignoring letter case. Requires deck.edit. Preview first, then confirm: true with the returned plan_state; a label changed in between needs a new approval.';

	public const TOOL_DELETE_LABEL_DESCRIPTION = 'Deletes a label from a Deck board you manage and removes it from every card that carries it, archived and deleted cards included. Deck has no trash for labels: this cannot be undone. Requires deck.delete. The plan shows how many cards carry the label. Preview first, then confirm: true with the returned plan_state; a label changed or assigned in between needs a new approval.';

	public const PARAM_LABEL_ID = 'Id of the Deck label, from deck_list_labels.';

	public const PARAM_LABEL_TITLE = 'Label title, 1 to 100 characters.';

	public const PARAM_LABEL_COLOR = 'Label colour as six hexadecimal digits, with or without a leading #, for example ff7a66.';

	public const PARAM_CARD_LABELS = 'Optional labels for the new card, at most 20, like the labels of a GitHub issue: a title (for example "bug" or "sev:medium") or the ID of a label of the board. A title matches an existing label ignoring letter case; a title the board does not have yet is created with a default colour, which needs deck.edit and permission to manage the board. The plan lists which labels are assigned and which are created.';

	public const GUIDE_NOTE = 'Board labels: deck_create_label, deck_update_label (deck.edit) and deck_delete_label (deck.delete) need permission to manage the board. A title matches ignoring letter case and two labels of a board cannot share one. Deleting a label removes it from every card and cannot be undone. deck_create_card accepts labels as titles or IDs: existing ones are assigned and missing titles are created on the board in the same confirmation (deck.edit and manage permission needed only for those), so a card can be filed like a GitHub issue with "bug" and "sev:medium". deck_edit_card_labels changes the labels of an existing card by ID.';

	/** @return string Localized refusal for a board that is archived, deleted or not managed by the caller. */
	public static function boardUnavailable(): string {
		return Translator::t('The labels of this board cannot be changed: the board is archived or deleted, or you cannot manage it.');
	}

	/** @return string Localized request to approve the refreshed plan. */
	public static function planChanged(): string {
		return Translator::t('The label changed since the plan was shown. Review and approve the new plan before trying again.');
	}

	/**
	 * @param string $title Title already on the board.
	 * @return string Localized refusal of a duplicate title.
	 */
	public static function titleTaken(string $title): string {
		return Translator::t('The board already has a label named %s, ignoring letter case. Use deck_list_labels to find it, or choose another title.', [PlanText::inline($title)]);
	}

	/**
	 * @param string $title Title sent for a card.
	 * @return string Localized refusal of a title that several labels match.
	 */
	public static function titleAmbiguous(string $title): string {
		return Translator::t('Several labels of the board match %s apart from letter case. Send the exact title or the label ID.', [PlanText::inline($title)]);
	}

	/**
	 * @param int $labelId ID sent for a card.
	 * @return string Localized refusal of an ID that is not a label of the board.
	 */
	public static function unknownLabelId(int $labelId): string {
		return Translator::t('The board of this list has no label with ID %s. Use deck_list_labels for its labels, or send a title.', [$labelId]);
	}

	/** @return string Localized validation rule for an update with nothing to change. */
	public static function errorNothingToChange(): string {
		return Translator::t('send a new title, a new color or both');
	}

	/** @return string Localized validation rule for the labels of a new card. */
	public static function errorCardLabels(): string {
		return Translator::t('use at most 20 label titles or positive label IDs');
	}

	/**
	 * @param list<string> $titles Titles the board does not have.
	 * @return string Localized refusal when the caller cannot manage the board.
	 */
	public static function creationNeedsManage(array $titles): string {
		return Translator::t('The board has no label %s, and creating labels needs permission to manage the board. Use existing labels (deck_list_labels) or ask the board owner.', [self::titles($titles)]);
	}

	/**
	 * @param list<string> $titles Titles the board does not have.
	 * @return string Localized refusal when the deck.edit grant is missing.
	 */
	public static function creationNeedsGrant(array $titles): string {
		return Translator::t('The board has no label %s, and creating labels needs the deck.edit permission of this app. Use existing labels (deck_list_labels) or ask an administrator.', [self::titles($titles)]);
	}

	/**
	 * @param list<string> $titles Labels that were not confirmed on the card.
	 * @return string Localized warning after the card was created.
	 */
	public static function cardLabelsFailed(array $titles): string {
		return Translator::t('The card was created, but the labels %s could not be created or assigned. Read the card and use deck_edit_card_labels before trying again.', [self::titles($titles)]);
	}

	/** @return string Localized warning when the card was only probably created. */
	public static function probableCardNoLabels(): string {
		return Translator::t('No label was created or assigned because the card was only probably created. Check it first.');
	}

	/** @return string Localized no-op answer of an update. */
	public static function unchanged(): string {
		return Translator::t('The label already has this title and colour; nothing was written.');
	}

	/** @return string Localized plan promise of a creation. */
	public static function planCreate(): string {
		return Translator::t('Nothing was changed. After your approval the label below is added to the board, without being assigned to any card.');
	}

	/** @return string Localized plan promise of an update. */
	public static function planUpdate(): string {
		return Translator::t('Nothing was changed. After your approval the label below changes on the board and on every card that carries it.');
	}

	/** @return string Localized plan promise of a deletion. */
	public static function planDelete(): string {
		return Translator::t('Nothing was changed. After your approval the label below is deleted from the board and removed from every card that carries it.');
	}

	/** @return string Localized irreversibility of a deletion. */
	public static function deleteConsequence(): string {
		return Translator::t('Deck has no trash for labels: this cannot be undone. Creating a label with the same title later does not put it back on the cards.');
	}

	/**
	 * @param list<string> $titles Label titles.
	 * @return string Escaped, comma-separated titles.
	 */
	private static function titles(array $titles): string {
		return implode(', ', array_map(static fn (string $title): string => PlanText::inline($title), $titles));
	}

	/** Constant and translated-message holder. */
	private function __construct() {
	}
}
