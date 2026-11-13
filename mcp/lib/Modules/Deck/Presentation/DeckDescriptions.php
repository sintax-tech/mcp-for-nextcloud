<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;


/** Keeps the existing Deck descriptions text unchanged. */
final class DeckDescriptions {
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
		= 'Creates a Deck card at the end of a list (stack), owned by you. Optional assignees are account IDs with access to the board; use users_search to resolve IDs. Optional labels work like the labels of a GitHub issue: titles or label IDs of the board, existing ones are assigned and new titles are created on the board in the same confirmation. If assignment or labelling fails after creation, the created card is returned with a warning.';

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

	public const TOOL_CARD_FILES_DESCRIPTION = 'Lists only visible existing native file associations of a readable Deck card. Requires deck.read and files.read, Files resource scope, exclusion tags and native node READ. No legacy deck_file or folders, no upload, no hidden count/handle; IDs are association/share IDs distinct from fileId. All visible items ordered by attachmentId; native Card attachmentCount stays omitted. Available on Nextcloud 32 through 35. An association you cannot see is omitted and answered like a missing one. Open the card in Deck without treating that answer as proof that a hidden association exists.';

	public const TOOL_UNLINK_FILE_DESCRIPTION = 'Unlinks one visible native file association, preserving the original file and other shares. Requires deck.edit and files.read, active editable source and Files scope/tags/node READ; no files.share grant is needed. Revokes this type-12 share for all current/future card members, including a share created by someone else; independent shares may retain access. Preview first, repeat plan_state and the exact file_state receipt with confirm:true. file_state is accepted only on confirm; invalid/replayed/stale receipt, hidden and nonexistent association share one refusal. A confirmed absent association is a no-op only with valid original HMAC and the original file still visible. Never unlinks invisible files, legacy deck_file or folders. Available on Nextcloud 32 through 35. An editor of the card can unlink a visible association created by someone else. A folder you can already see, and an archived card or board, are refused in plain language.';

	public const TOOL_ADD_DEPENDENCY_DESCRIPTION = 'Adds one native outgoing Deck dependency cardId -> targetId on NC34/35. Requires deck.edit, origin EDIT and target READ. Refuses self-relations and cycles detectable in the bounded readable graph (100 nodes, 500 edges, depth 20); never promises a global DAG. Cycles through unreadable cards and the final check-to-write race cannot be excluded. Other links are preserved; duplicate is a no-op. Preview first, then confirm:true with the returned plan_state.';

	public const TOOL_REMOVE_DEPENDENCY_DESCRIPTION = 'Removes only cardId -> targetId through the native Deck API on NC34/35. Requires deck.edit, origin EDIT and target READ even for removal. Hidden and nonexistent targets have the same refusal; no hidden count or handle. Missing relation is a no-op. Preview first, then confirm:true with the returned plan_state.';

	public const TOOL_READ_DEPENDENCIES_DESCRIPTION = 'Reads the complete outgoing native dependency subgraph reachable through readable cards. For native assignDependentCard(A,B), B appears in A.dependentCards: originId=A and targetId=B, without automatic task blocking. Nodes sorted by ID; edges sorted by originId/targetId. No pagination: at most 100 readable nodes (including root), 500 readable edges and shortest-path depth 20; refuses rather than returning an incomplete graph. Never reports hidden IDs, counts, handles or a hidden-limit indicator. Cycles through unreadable cards cannot be detected. Available only on compatible NC34/35 native APIs; exact releases proved: Deck1.18.5/1.19.0.';

	/** Constant and translated-message holder. */
	private function __construct() {
	}
}
