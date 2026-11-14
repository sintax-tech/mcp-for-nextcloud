<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Descriptions, plans, refusals and warnings of the board label tools and of labels on a new card. */
final class DeckLabelMessages {
	public const TOOL_CREATE_LABEL_DESCRIPTION = 'Creates a label on a Deck board you manage (owner or manage permission), without assigning it to any card. The title (1 to 100 characters) must not match an existing label of the board, ignoring letter case; use deck_list_labels first. Without color, the same title always gets the same colour. Requires deck.labels. Preview first, then confirm: true with the returned plan_state. To create a card with labels in one step, use deck_create_card with labels.';

	public const TOOL_UPDATE_LABEL_DESCRIPTION = 'Renames or recolours a label of a Deck board you manage; every card carrying it shows the change. Send title, color or both. The new title must not match another label of the board, ignoring letter case. Requires deck.labels. Preview first, then confirm: true with the returned plan_state; a label changed in between needs a new approval.';

	public const TOOL_DELETE_LABEL_DESCRIPTION = 'Deletes a label from a Deck board you manage and removes it from every card that carries it, archived and deleted cards included. Deck has no trash for labels: this cannot be undone. Requires deck.labels. The plan shows how many cards carry the label. Preview first, then confirm: true with the returned plan_state; a label changed or assigned in between needs a new approval.';

	public const PARAM_LABEL_ID = 'Id of the Deck label, from deck_list_labels.';

	public const PARAM_LABEL_TITLE = 'Label title, 1 to 100 characters.';

	public const PARAM_LABEL_COLOR = 'Label colour as six hexadecimal digits, with or without a leading #, for example ff7a66.';

	public const PARAM_CARD_LABELS = 'Optional labels for the new card, at most 20, like the labels of a GitHub issue: a title (for example "bug" or "sev:medium") or the ID of a label of the board. A title matches an existing label exactly; case-only differences require an explicit label ID; a title the board does not have yet is created with a default colour, which needs permission to manage the board. Any use of labels requires deck.labels, including existing IDs or an empty list. The plan lists which labels are assigned and which are created.';

	public const GUIDE_NOTE = 'Board labels: deck_create_label, deck_update_label (deck.labels) and deck_delete_label (deck.labels) need permission to manage the board. Titles resolve exactly; case-only alternatives require an explicit label ID. Deleting a label removes it from every card and cannot be undone. deck_create_card accepts labels as titles or IDs: existing ones are assigned and missing titles are created on the board in the same confirmation (deck.labels always required; native manage permission required for new labels), so a card can be filed like a GitHub issue with "bug" and "sev:medium". deck_edit_card_labels changes the labels of an existing card by ID.';

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
	 * @return string Localized refusal of case-only collisions in the board or request.
	 */
	public static function titleAmbiguous(string $title): string {
		return Translator::t('Label titles conflict apart from letter case: %s. Send an exact title or a label ID; do not request case-only variants together.', [PlanText::inline($title)]);
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

	/** @return string Localized refusal when card creation lacks the deck.labels grant. */
	public static function creationNeedsGrant(): string {
		return Translator::t('Using labels while creating a card requires the deck.labels permission of this app. Ask an administrator.');
	}

	/** @return string Localized refusal when board label management lacks its grant. */
	public static function managementNeedsGrant(): string {
		return Translator::t('Managing board labels requires the deck.labels permission of this app. Ask an administrator.');
	}

	/**
	 * @param list<string> $titles Labels that were not confirmed on the card.
	 * @return string Localized warning after the card was created.
	 */
	public static function cardLabelsFailed(array $titles): string {
		return Translator::t('The card was created, but labels %s were not completed. Some writes may have succeeded; later steps were stopped. Read the board and card before making any new request.', [self::titles($titles)]);
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
