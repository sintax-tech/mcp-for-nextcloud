<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;

/** Keeps the existing Deck refusal text unchanged. */
final class DeckRefusalMessages {
	public const PARAM_LABEL_ADD = 'Label IDs to assign incrementally, from the card board (maximum 100).';

	public const PARAM_LABEL_REMOVE = 'Label IDs to unassign incrementally, from the card board (maximum 100).';

	public const PARAM_ATTACHMENT_ID = 'Bare native type-12 Deck file share ID returned as attachmentId by deck_list_card_files, not fileId. Legacy deck_file and folders are unsupported.';

	public const PARAM_DEPENDENCY_TARGET = 'Readable target card ID. For native assignDependentCard(A,B), A is cardId and B is targetId.';

	public const PARAM_BOARD_ID = 'Id of the Deck board.';

	public const PARAM_STACK_ID = 'Id of the Deck list (stack).';

	public const PARAM_CARD_ID = 'Id of the Deck card.';

	public const PARAM_STATUS = 'Status filter: overdue (due before today), open, done or all. Default: overdue.';

	public const PARAM_ASSIGNEES = 'Optional list of account IDs to assign (maximum 100). Each account must exist and have access to the board. Omit or send an empty list to create without explicit assignments.';

	public const PARAM_ASSIGN = 'Optional list of account IDs to assign to the card (maximum 100). Each account must exist and have access to the board. An account already assigned is ignored with a warning. Cannot share an account with unassign.';

	public const PARAM_UNASSIGN = 'Optional list of account IDs to remove from the card (maximum 100). An account that is not assigned today is ignored with a warning. Cannot share an account with assign.';

	public const PARAM_ASSIGNEE = 'UID of the assignee (if you are not sure of someone\'s ID, call users_search first); returns only the cards assigned to that person.';

	public const PARAM_DUE_BEFORE = 'Returns only cards due on or before this date, in YYYY-MM-DD format.';

	public const PARAM_TITLE = 'Card title.';

	public const PARAM_DESCRIPTION = 'Card description, in plain text.';

	public const PARAM_DUEDATE = \OCA\Mcp\Modules\Deck\DueDates\CardDueDateMessages::INPUT_DESCRIPTION;

	public const PARAM_LIMIT = 'Maximum number of cards to return.';

	public const PARAM_LABEL_LIMIT = 'Maximum number of labels to return.';

	public const PARAM_LABEL_OFFSET = 'Number of labels to skip.';

	public const PARAM_OFFSET = 'Number of cards to skip, for pagination.';

	public const PARAM_ORDER = 'Position of the card in the list, from 0 to 99999.';

	public const PARAM_LAST_MODIFIED = 'lastModified of the card at the time it was read; if it has changed, the edit is refused.';

	public const PARAM_BOARD_TITLE = 'Board title, 1 to 100 characters.';

	public const PARAM_BOARD_COLOR = 'Board colour as six hexadecimal digits, with or without a leading #, for example 0082c9. Default: the Nextcloud blue.';

	public const PARAM_BOARD_STACKS = 'Lists to create on the new board, in order (maximum 20). Each one may carry its first cards (maximum 100 cards in total, in all lists).';

	public const PARAM_STACK_TITLE = 'List title, 1 to 100 characters.';

	public const PARAM_STACK_CARDS = 'Cards to create at the end of this list, in order.';

	public const PARAM_STACK_ORDER = 'Position among the lists of the board, from 0 to 99999. Without it the list goes after the last one.';

	public const PARAM_CONFIRM_SHARED = 'Set to true only after the user has confirmed the change on somebody else\'s board.';

	public const CONFIRM_SHARED_DESCRIPTION
		= ' If the resource belongs to somebody else, the agent MUST ask the user before sending confirm_shared: true.';

	/** @return string Localized validation rule for label ID lists. */
	public static function errorLabelIds(): string {
		return Translator::t('use a list of positive integer label IDs, at most 100');
	}

	/** @return string Localized refusal of conflicting add/remove requests. */
	public static function errorLabelOverlap(): string {
		return Translator::t('a label cannot be added and removed in the same call');
	}

	/** @return string Localized requirement for a nonempty requested delta. */
	public static function errorLabelEmpty(): string {
		return Translator::t('request at least one label to add or remove');
	}

	/** @return string Localized refusal without disclosing an inaccessible label or card. */
	public static function errorLabelsUnavailable(): string {
		return Translator::t('The card or requested labels are unavailable for editing. Use an active card and labels from its own board.');
	}

	/** @return string Localized request to approve the refreshed plan. */
	public static function labelPlanChanged(): string {
		return Translator::t('The card, board or labels changed. Review and approve the new plan before changing labels.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized guidance when current labels cannot be read safely. */
	public static function labelsUnconfirmed(): string {
		return Translator::t('The label result could not be verified. Check the card in Deck before taking any further action; do not repeat this write.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized guidance after an incomplete sequence. */
	public static function labelsPartial(): string {
		return Translator::t('Label changes stopped before all requested associations were confirmed. Review the current card in Deck and request a fresh plan before any further changes.');
	}

	/** @return string Localized observation without attributing another actor’s change to this call. */
	public static function labelStateObserved(): string {
		return Translator::t('The requested label state was observed after an error. This call may not have made that change; check the current labels before taking any further action.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/**
	 * Shared by "not found" and "no permission": the Deck answers both with the same exception.
	 * @return string The message in the language of the current user.
	 */
	public static function errorNotFoundOrForbidden(): string {
		return Translator::t('Card, list or board not found, or no permission.');
	}

	/**
	 * Board or card archived.
	 * @return string The message in the language of the current user.
	 */
	public static function errorNotAllowed(): string {
		return Translator::t('Operation not allowed on this Deck board or card.');
	}

	/**
	 * A list is deleted only when it holds no card, active or archived.
	 * @param int $cards Cards the list holds.
	 * @return string The message in the language of the current user.
	 */
	public static function errorStackNotEmpty(int $cards): string {
		return Translator::n('The list has %n card; move or delete it first.', 'The list has %n cards; move or delete them first.', $cards);
	}

	/**
	 * A board is deleted only when none of its lists holds a card, active or archived.
	 * @param int $cards Cards the board holds.
	 * @return string The message in the language of the current user.
	 */
	public static function errorBoardNotEmpty(int $cards): string {
		return Translator::n('The board has %n card; move or delete it first.', 'The board has %n cards; move or delete them first.', $cards);
	}

	/**
	 * A card was created in a list while it was being deleted, so the delete was taken back.
	 * @param bool $restored Whether the list is back; false when it stayed in the Deck trash.
	 * @return string The message in the language of the current user.
	 */
	public static function errorStackReceivedCards(bool $restored): string {
		return $restored
			? Translator::t('The list received cards while it was being deleted; nothing was deleted.')
			: Translator::t('The list received cards while it was being deleted and could not be restored; recover it from the Deck trash.');
	}

	/**
	 * A card was created in a list of the board while it was being deleted, so the delete was taken back.
	 * @param bool $restored Whether the board is back; false when it stayed in the Deck trash.
	 * @return string The message in the language of the current user.
	 */
	public static function errorBoardReceivedCards(bool $restored): string {
		return $restored
			? Translator::t('The board received cards while it was being deleted; nothing was deleted.')
			: Translator::t('The board received cards while it was being deleted and could not be restored; recover it from the Deck trash.');
	}

	/**
	 * Only the owner deletes a board, even when others may manage it.
	 * @return string The message in the language of the current user.
	 */
	public static function errorBoardNotOwned(): string {
		return Translator::t('Only the owner of a board can delete it.');
	}

	/**
	 * The Deck validator rejected the payload.
	 * @return string The message in the language of the current user.
	 */
	public static function errorInvalid(): string {
		return Translator::t('Invalid data for Deck.');
	}

	/**
	 * The card changed between the read and the write.
	 * @return string The message in the language of the current user.
	 */
	public static function errorConflict(): string {
		return Translator::t('The Deck item changed during the operation. Try again.');
	}

	/**
	 * The session the Deck services read is not the authenticated caller's; refused before writing.
	 * @return string The message in the language of the current user.
	 */
	public static function errorSessionNotBound(): string {
		return Translator::t('The Deck could not be bound to the authenticated account. Nothing was changed; try again.');
	}

	/**
	 * Fallback for anything unexpected.
	 * @return string The message in the language of the current user.
	 */
	public static function errorGeneric(): string {
		return Translator::t('Could not complete the operation in Deck.');
	}

	/**
	 * Raised when deck_edit_card is called without a single editable field.
	 * @return string The message in the language of the current user.
	 */
	public static function errorNoFieldToEdit(): string {
		return Translator::t('Provide at least one field to edit: title, description, duedate, assign or unassign.');
	}

	/**
	 * Raised when `dueBefore` is not a real calendar date in YYYY-MM-DD form.
	 * @return string The message in the language of the current user.
	 */
	public static function errorInvalidDueBefore(): string {
		return Translator::t('The dueBefore date must be a valid date in YYYY-MM-DD format.');
	}

	/**
	 * Raised when `deck_followup_cards` is called with a status the Deck does not group by.
	 * @return string The message in the language of the current user.
	 */
	public static function errorInvalidStatus(): string {
		return Translator::t('Invalid status for the follow-up. Use overdue, open, done or all.');
	}

	/**
	 * Raised when a description is longer than the module accepts.
	 * @return string The message in the language of the current user.
	 */
	public static function errorDescriptionTooLong(): string {
		return Translator::t('The card description exceeds the maximum size of 100000 characters.');
	}

	/**
	 * Raised when the title is empty once trimmed.
	 * @return string The message in the language of the current user.
	 */
	public static function errorTitleRequired(): string {
		return Translator::t('The card title cannot be empty.');
	}

	/**
	 * Raised for a tool name this module does not serve.
	 * @return string The message in the language of the current user.
	 */
	public static function errorUnknownTool(): string {
		return Translator::t('Unknown Deck tool.');
	}

	/**
	 * Raised when the Deck app is not available to the caller.
	 * @return string The message in the language of the current user.
	 */
	public static function errorDeckAppUnavailable(): string {
		return Translator::t('The Deck app is not available for this user.');
	}

	/**
	 * Message returned, without error, instead of writing on somebody else's board.
	 * @param string $board Name of the board.
	 * @param string $owner Display name of its owner.
	 * @return string The message in the language of the current user.
	 */
	public static function sharedConfirmation(string $board, string $owner): string {
		return Translator::t('The board \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.', [$board, $owner]);
	}

	/** Constant and translated-message holder. */
	private function __construct() {
	}
}
