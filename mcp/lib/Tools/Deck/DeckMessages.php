<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of the Deck module.
 *
 * Tool and parameter descriptions are read by the model, not by the person, so they are fixed English constants.
 * What the user can see (errors, the shared-board confirmation) is a static method that translates an English
 * source text through {@see Translator}, in the language of the authenticated user.
 */
final class DeckMessages {
	/* Tool descriptions, exposed to MCP clients in tools/list. */
	public const TOOL_LIST_BOARDS_DESCRIPTION
		= 'Lists the Deck boards shared with you, excluding archived ones.';
	public const TOOL_LIST_STACKS_DESCRIPTION
		= 'Lists the lists (stacks) of a Deck board, with the number of cards in each.';
	public const TOOL_LIST_CARDS_DESCRIPTION
		= 'Lists the active cards of a Deck list (stack), paginated.';
	public const TOOL_READ_CARD_DESCRIPTION
		= 'Reads a Deck card with its description, dates and counters.';
	public const TOOL_CREATE_CARD_DESCRIPTION
		= 'Creates a Deck card at the end of a list (stack), owned by you. Optional assignees are account IDs with access to the board; use users_search to resolve IDs. If assignment fails after creation, the created card is returned with a warning.';
	public const TOOL_EDIT_CARD_DESCRIPTION
		= 'Edits the title, description, due date and assignees of a Deck card. Use assign and unassign (lists of account IDs; use users_search to resolve IDs) to change who is responsible for it. Use lastModified to avoid overwriting a concurrent edit. If an assignment fails midway, the result lists what was assigned, unassigned and failed; nothing is undone.';
	public const TOOL_MOVE_CARD_DESCRIPTION
		= 'Moves a Deck card to another list (stack), including one on another board. Without order, the card goes to the end.';
	public const TOOL_DELETE_CARD_DESCRIPTION
		= 'Deletes a Deck card the same way the web interface does: the deletion can be undone in Deck.';
	public const TOOL_FOLLOWUP_CARDS_DESCRIPTION
		= 'Lists the cards of the boards you can manage (owner or manage permission), grouped by assignee, with a direct link to each card. By default it returns only overdue cards; the scan covers at most 100 visible boards.';

	public const TOOL_CREATE_BOARD_DESCRIPTION
		= 'Creates a Deck board owned by you, with its lists (stacks) and first cards, in one call and one confirmation: use it to set up a project from scratch instead of creating the pieces one by one. At most 20 lists and 100 cards in total; the cards go to the end of each list, in the order given. The new board is open only to you, so assignees can only be your own account (share the board in Deck first to assign others). If a step fails midway, the result lists what was created and what failed; nothing is undone, so read it before retrying.';
	public const TOOL_CREATE_STACK_DESCRIPTION
		= 'Creates a list (stack) on a Deck board you manage, at the end or at the given position. To build a whole board with its lists and cards, use deck_create_board.';
	public const TOOL_DELETE_STACK_DESCRIPTION
		= 'Deletes a Deck list (stack), only if it holds no cards, active or archived: move or delete the cards first. Deck moves the list to the trash of the board, where it can be recovered.';
	public const TOOL_DELETE_BOARD_DESCRIPTION
		= 'Deletes a Deck board you own, only if none of its lists holds a card, active or archived. Its empty lists go with it. Deck moves the board to its trash, where it can be recovered.';

	/* Parameter descriptions. */
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
	public const PARAM_DUEDATE = 'Due date in YYYY-MM-DD format, or null to remove it.';
	public const PARAM_LIMIT = 'Maximum number of cards to return.';
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

	/* Shared-resource confirmation (confirm_shared). */
	/** Sentence appended to the description of every write tool of the module. */
	public const CONFIRM_SHARED_DESCRIPTION
		= ' If the resource belongs to somebody else, the agent MUST ask the user before sending confirm_shared: true.';

	private function __construct() {
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
	 * Raised when a date is not a real calendar date in YYYY-MM-DD form.
	 * @return string The message in the language of the current user.
	 */
	public static function errorInvalidDuedate(): string {
		return Translator::t('The due date must be a valid date in YYYY-MM-DD format.');
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

	/* Plan of a write: the answer to a call that arrives without confirm, nothing having been written. */

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planCreate(): string {
		return Translator::t('Nothing was changed. After your approval a new card is created at the end of the list below, owned by you. You are not assigned to it unless listed below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planEdit(): string {
		return Translator::t('Nothing was changed. After your approval the fields below are written over the card.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planMove(): string {
		return Translator::t('Nothing was changed. After your approval the card moves from the list below to the destination below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDelete(): string {
		return Translator::t('Nothing was changed. After your approval the card is deleted from the board below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planCreateBoard(): string {
		return Translator::t('Nothing was changed. After your approval the board below is created for you, with its lists and cards, in this order. Nothing is undone if a step fails midway; the result says what exists.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planCreateStack(): string {
		return Translator::t('Nothing was changed. After your approval a new list is created on the board below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteStack(): string {
		return Translator::t('Nothing was changed. After your approval the empty list below is deleted.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteBoard(): string {
		return Translator::t('Nothing was changed. After your approval the empty board below is deleted.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteStackConsequence(): string {
		return Translator::t('The list goes to the trash of the board and can be recovered there, in the Deck web interface.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteBoardConsequence(): string {
		return Translator::t('The board, with its empty lists, goes to the Deck trash and can be recovered there, in the Deck web interface.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteConsequence(): string {
		return Translator::t('Deck deletes the card the way the web interface does: it leaves the lists, and only the board owner or an administrator can bring it back from the Deck web interface. No tool of this app undoes it.');
	}
}
