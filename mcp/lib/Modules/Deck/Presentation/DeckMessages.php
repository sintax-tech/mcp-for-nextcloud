<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

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
	public const TOOL_LIST_LABELS_DESCRIPTION
		= 'Lists the labels available on a readable Deck board, with their IDs, titles and colours. Available on Nextcloud 32 through 35 when Deck is enabled (proved with Deck 1.16.8, 1.17.7, 1.18.5 and 1.19.0).';
	public const TOOL_LIST_STACKS_DESCRIPTION
		= 'Lists the lists (stacks) of a Deck board, with the number of cards in each.';
	public const TOOL_LIST_CARDS_DESCRIPTION
		= 'Lists the active cards of a Deck list (stack), paginated.';
	public const TOOL_READ_CARD_DESCRIPTION
		= 'Reads a Deck card with its description and dates.';
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
		= 'Deletes a Deck list (stack), only if it holds no cards, active or archived: move or delete the cards first. Deck moves the list to the trash of the board, where it can be recovered. A card created by someone else right after the second count the tool takes before finishing may go to the trash with the list; it is recoverable there.';
	public const TOOL_DELETE_BOARD_DESCRIPTION
		= 'Deletes a Deck board you own, only if none of its lists holds a card, active or archived. Its empty lists go with it. Deck moves the board to its trash, where it can be recovered. A card created by someone else right after the second count the tool takes before finishing may go to the trash with the board; it is recoverable there.';

	public const TOOL_EDIT_LABELS_DESCRIPTION = 'Adds or removes only the requested label IDs on a Deck card; preserves all other labels. Use deck_list_labels for that board. Preview first and repeat plan_state with confirm: true. At most 100 IDs per list; duplicates and already satisfied requests are unchanged; an ID cannot occur in both lists. A foreign-board label is refused. Read partial results before any further action. Available on Nextcloud 32 through 35.';
	public const PARAM_LABEL_ADD = 'Label IDs to assign incrementally, from the card board (maximum 100).';
	public const PARAM_LABEL_REMOVE = 'Label IDs to unassign incrementally, from the card board (maximum 100).';

	public const PARAM_ATTACHMENT_ID = 'Bare native type-12 Deck file share ID returned as attachmentId by deck_list_card_files, not fileId. Legacy deck_file and folders are unsupported.';
	public const TOOL_CARD_FILES_DESCRIPTION = 'Lists only visible existing native file associations of a readable Deck card. Requires deck.read and files.read, Files resource scope, exclusion tags and native node READ. No legacy deck_file or folders, no upload, no hidden count/handle; IDs are association/share IDs distinct from fileId. All visible items ordered by attachmentId; native Card attachmentCount stays omitted. Available on Nextcloud 32 through 35. An association you cannot see is omitted and answered like a missing one. Open the card in Deck without treating that answer as proof that a hidden association exists.';
	public const TOOL_UNLINK_FILE_DESCRIPTION = 'Unlinks one visible native file association, preserving the original file and other shares. Requires deck.edit and files.read, active editable source and Files scope/tags/node READ; no files.share grant is needed. Revokes this type-12 share for all current/future card members, including a share created by someone else; independent shares may retain access. Preview first, repeat plan_state and the exact file_state receipt with confirm:true. file_state is accepted only on confirm; invalid/replayed/stale receipt, hidden and nonexistent association share one refusal. A confirmed absent association is a no-op only with valid original HMAC and the original file still visible. Never unlinks invisible files, legacy deck_file or folders. Available on Nextcloud 32 through 35. An editor of the card can unlink a visible association created by someone else. A folder you can already see, and an archived card or board, are refused in plain language.';
	public const PARAM_DEPENDENCY_TARGET = 'Readable target card ID. For native assignDependentCard(A,B), A is cardId and B is targetId.';
	public const TOOL_ADD_DEPENDENCY_DESCRIPTION = 'Adds one native outgoing Deck dependency cardId -> targetId on NC34/35. Requires deck.edit, origin EDIT and target READ. Refuses self-relations and cycles detectable in the bounded readable graph (100 nodes, 500 edges, depth 20); never promises a global DAG. Cycles through unreadable cards and the final check-to-write race cannot be excluded. Other links are preserved; duplicate is a no-op. Preview first, then confirm:true with the returned plan_state.';
	public const TOOL_REMOVE_DEPENDENCY_DESCRIPTION = 'Removes only cardId -> targetId through the native Deck API on NC34/35. Requires deck.edit, origin EDIT and target READ even for removal. Hidden and nonexistent targets have the same refusal; no hidden count or handle. Missing relation is a no-op. Preview first, then confirm:true with the returned plan_state.';
	public const TOOL_READ_DEPENDENCIES_DESCRIPTION = 'Reads the complete outgoing native dependency subgraph reachable through readable cards. For native assignDependentCard(A,B), B appears in A.dependentCards: originId=A and targetId=B, without automatic task blocking. Nodes sorted by ID; edges sorted by originId/targetId. No pagination: at most 100 readable nodes (including root), 500 readable edges and shortest-path depth 20; refuses rather than returning an incomplete graph. Never reports hidden IDs, counts, handles or a hidden-limit indicator. Cycles through unreadable cards cannot be detected. Available only on compatible NC34/35 native APIs; exact releases proved: Deck1.18.5/1.19.0.';

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

	/* Shared-resource confirmation (confirm_shared). */
	/** Sentence appended to the description of every write tool of the module. */
	public const CONFIRM_SHARED_DESCRIPTION
		= ' If the resource belongs to somebody else, the agent MUST ask the user before sending confirm_shared: true.';

	private function __construct() {
	}

	/** @return string Localized dependency dependenciesUnavailable message. */
	public static function dependenciesUnavailable(): string {
		return Translator::t('Native Deck dependency reading is unavailable on this installation.');
	}

	/** @return string Localized dependency dependencyGraphLimit message. */
	public static function dependencyGraphLimit(): string {
		return Translator::t('The readable dependency graph exceeds the traversal limits. No incomplete graph was returned.');
	}

	/** @return string Localized dependency dependencyGraphVisibility message. */
	public static function dependencyGraphVisibility(): string {
		return Translator::t('This graph contains only readable cards. Cycles through unreadable cards cannot be detected; a visible graph does not prove that the global graph is acyclic. Native relations do not automatically block task progress.');
	}

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

	/** @return string Localized promise that unrelated label associations remain unchanged. */
	public static function planLabels(): string {
		return Translator::t('Only the requested label associations will change. All other labels will be preserved.');
	}

	/** @return string Localized request to approve the refreshed plan. */
	public static function labelPlanChanged(): string {
		return Translator::t('The card, board or labels changed. Review and approve the new plan before changing labels.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized guidance when current labels cannot be read safely. */
	public static function labelsUnconfirmed(): string {
		return Translator::t('The label result could not be verified. Check the card in Deck before taking any further action; do not repeat this write.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized guidance after an incomplete sequence. */
	public static function labelsPartial(): string {
		return Translator::t('Label changes stopped before all requested associations were confirmed. Review the current card in Deck and request a fresh plan before any further changes.');
	}

	/** @return string Localized observation without attributing another actor’s change to this call. */
	public static function labelStateObserved(): string {
		return Translator::t('The requested label state was observed after an error. This call may not have made that change; check the current labels before taking any further action.')
			. ' ' . self::doesNotProveTheWholeRequest();
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
	 * Deck saved the write and failed afterwards, in an activity, event or notification: the result stands.
	 * @return string The message in the language of the current user.
	 */
	public static function writtenThenFailed(): string {
		return Translator::t('Saved; Deck reported an error afterwards (notification or activity).');
	}

	/**
	 * A creation threw and the read-back found an item that only looks like it (same title, owner and moment, or a
	 * list or board that was not in the snapshot): it may belong to another call, so nothing is built on it.
	 * @return string The message in the language of the current user.
	 */
	public static function probablyCreated(): string {
		return Translator::t('Probably created: check it before continuing.');
	}

	/**
	 * Assignees of a card that was only probably created are left alone.
	 * @return string The message in the language of the current user.
	 */
	public static function probableCardNotAssigned(): string {
		return Translator::t('The assignees were not assigned because the card was only probably created. Check it first.');
	}

	/**
	 * Reason of a list or card of deck_create_board that was not created because the board or list it belongs to
	 * was found only by a likeness.
	 * @return string The message in the language of the current user.
	 */
	public static function probableParentNotBuilt(): string {
		return Translator::t('Not created: what it belongs to was only probably created. Check it, then create this one.');
	}

	/**
	 * A write threw and the state could not be read back: unknown, so read before retrying instead of repeating.
	 * @param string $readWith Tool that reads the item back.
	 * @return string The message in the language of the current user.
	 */
	public static function writeUnconfirmed(string $readWith): string {
		return Translator::t('Could not confirm whether Deck saved this change; read it with %s before trying again.', [$readWith]);
	}

	/**
	 * One list or card of deck_create_board whose creation could not be confirmed.
	 * @param string $kind `list` or `card`.
	 * @param string $title Its title.
	 * @return string The message in the language of the current user.
	 */
	public static function boardItemUnconfirmed(string $kind, string $title): string {
		return $kind === 'list'
			? Translator::t('Could not confirm whether the list \'%s\' was created; read the board before retrying.', [$title])
			: Translator::t('Could not confirm whether the card \'%s\' was created; read the list before retrying.', [$title]);
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
	/** @return string Localized dependency change message. */
	public static function dependencyCycle(): string {
		return Translator::t('Self-relations and cycles detectable through readable cards are refused.');
	}

	/** @return string Localized dependency change message. */
	public static function dependencyInactive(): string {
		return Translator::t('Dependency changes require active cards and boards.');
	}

	/** @return string Localized dependency change message. */
	public static function dependencyPlanChanged(): string {
		return Translator::t('The readable dependency state changed. Review the new plan before confirming.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized dependency change message. */
	public static function dependencyUnconfirmed(): string {
		return Translator::t('The dependency result could not be confirmed. Check the Deck interface; do not repeat the write automatically.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized dependency change message. */
	public static function dependencyStateObserved(): string {
		return Translator::t('The requested relation state was observed after a failure. Another actor may have produced it; this does not prove which write changed it.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/**
	 * State direction, the readable-cycle refusal and the gap a hidden card leaves.
	 *
	 * @return string Localized dependency change message.
	 */
	public static function dependencyWriteLimitations(): string {
		return Translator::t('Only the requested relation changes. Direction is from this card to the target. Self-relations and cycles through readable cards are refused. Relations to cards you cannot read are omitted, with no count and no id. Cycles through those cards cannot be detected. A concurrent change between the final check and the native write can still create a cycle.');
	}

	/**
	 * Name an archived source only after the caller can already see that card or board.
	 *
	 * @return string Localized file association message.
	 */
	public static function fileSourceInactive(): string {
		return Translator::t('An archived card or board cannot gain or lose a file association.');
	}

	/**
	 * Name a folder only when every alias is a folder the caller can already see.
	 *
	 * @return string Localized file association message.
	 */
	public static function fileFolderRefused(): string {
		return Translator::t('Folders cannot be linked or unlinked here. Choose a file.');
	}

	/**
	 * Keep a partial or uncertain outcome from being read as the whole request or as human approval.
	 *
	 * The sentence does not deny a step that was already verified.
	 *
	 * @return string Localized sentence appended to plan-changed and uncertain outcomes.
	 */
	public static function doesNotProveTheWholeRequest(): string {
		return Translator::t('This message does not prove that the whole request finished, and it is not a record of human approval.');
	}

	/** @return string Localized file association message. */
	public static function filePlanChanged(): string {
		return Translator::t('The file association state changed. Request a new plan before confirming.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized file association message. */
	public static function fileUnconfirmed(): string {
		return Translator::t('The file association result could not be confirmed. Check the Deck interface; do not repeat the unlink automatically.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized file association message. */
	public static function fileUnlinkObserved(): string {
		return Translator::t('The association was absent after a native failure. Another actor may have removed it; this does not prove which operation removed it.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}

	/** @return string Localized file association message. */
	public static function fileUnlinkWarning(): string {
		return Translator::t('The original file is preserved. Unlinking revokes this association for all current and future card members, including when another member created it. Independent shares may still provide access. This removes only a native file share, never a legacy upload or folder.');
	}

	/** @return string Exactly the native denied/missing public refusal; no hidden-association marker. */
	public static function fileUnavailable(): string {
		return self::errorNotFoundOrForbidden();
	}

}
