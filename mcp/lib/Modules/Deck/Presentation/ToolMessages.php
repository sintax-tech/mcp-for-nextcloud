<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;


class ToolMessages {
    public const TOOL_LIST_BOARDS_DESCRIPTION = 'Lists the Deck boards shared with you, excluding archived ones.';
    public const TOOL_LIST_STACKS_DESCRIPTION = 'Lists the lists (stacks) of a Deck board, with the number of cards in each.';
    public const TOOL_LIST_CARDS_DESCRIPTION = 'Lists the active cards of a Deck list (stack), paginated.';
    public const TOOL_READ_CARD_DESCRIPTION = 'Reads a Deck card with its description, dates and counters.';
    public const TOOL_CREATE_CARD_DESCRIPTION = 'Creates a Deck card at the end of a list (stack), owned by you. Optional assignees are account IDs with access to the board; use users_search to resolve IDs. If assignment fails after creation, the created card is returned with a warning.';
    public const TOOL_EDIT_CARD_DESCRIPTION = 'Edits the title, description, due date and assignees of a Deck card. Use assign and unassign (lists of account IDs; use users_search to resolve IDs) to change who is responsible for it. Use lastModified to avoid overwriting a concurrent edit. If an assignment fails midway, the result lists what was assigned, unassigned and failed; nothing is undone.';
    public const TOOL_MOVE_CARD_DESCRIPTION = 'Moves a Deck card to another list (stack), including one on another board. Without order, the card goes to the end.';
    public const TOOL_DELETE_CARD_DESCRIPTION = 'Deletes a Deck card the same way the web interface does: the deletion can be undone in Deck.';
    public const TOOL_FOLLOWUP_CARDS_DESCRIPTION = 'Lists the cards of the boards you can manage (owner or manage permission), grouped by assignee, with a direct link to each card. By default it returns only overdue cards; the scan covers at most 100 visible boards.';
    public const TOOL_CREATE_BOARD_DESCRIPTION = 'Creates a Deck board owned by you, with its lists (stacks) and first cards, in one call and one confirmation: use it to set up a project from scratch instead of creating the pieces one by one. At most 20 lists and 100 cards in total; the cards go to the end of each list, in the order given. The new board is open only to you, so assignees can only be your own account (share the board in Deck first to assign others). If a step fails midway, the result lists what was created and what failed; nothing is undone, so read it before retrying.';
    public const TOOL_CREATE_STACK_DESCRIPTION = 'Creates a list (stack) on a Deck board you manage, at the end or at the given position. To build a whole board with its lists and cards, use deck_create_board.';
    public const TOOL_DELETE_STACK_DESCRIPTION = 'Deletes a Deck list (stack), only if it holds no cards, active or archived: move or delete the cards first. Deck moves the list to the trash of the board, where it can be recovered. A card created by someone else right after the second count the tool takes before finishing may go to the trash with the list; it is recoverable there.';
    public const TOOL_DELETE_BOARD_DESCRIPTION = 'Deletes a Deck board you own, only if none of its lists holds a card, active or archived. Its empty lists go with it. Deck moves the board to its trash, where it can be recovered. A card created by someone else right after the second count the tool takes before finishing may go to the trash with the board; it is recoverable there.';
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
    public const CONFIRM_SHARED_DESCRIPTION = ' If the resource belongs to somebody else, the agent MUST ask the user before sending confirm_shared: true.';
}
