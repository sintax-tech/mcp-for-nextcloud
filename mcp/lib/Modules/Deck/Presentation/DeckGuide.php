<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\Modules\Deck\Structure\BoardBlueprint;
use OCA\Mcp\Modules\Deck\Cards\CardInput;

class DeckGuide {
    /**
     * How a card is found and where the writes stop: the three ids, the shared board and the field limits.
     *
     * @return list<string>
     */
    public static function notes(): array {
        return [
            'A card is addressed by three ids: deck_list_boards gives the board, deck_list_stacks the lists of a ' . 'board, and deck_list_cards the cards of a list. Read them in that order before writing anything.',
            'deck_read_card returns the card with its comments; deck_followup_cards lists the cards that are due or ' . 'overdue, per board.',
            'A board owned by somebody else is refused on a write until the user has been asked and the call repeats ' . 'with confirm_shared: true. The refusal is a normal result that says what is missing, not an error.',
            'The caller owns a created card. Explicit assignments are checked before creation and shown in the plan. ' . 'Assignment changes during creation can produce a created card with warnings; read it before retrying in Deck. ' . 'deck_edit_card changes assignees with assign and unassign (lists of account IDs, at most 100 each); ' . 'an account cannot be in both, accounts are checked before anything is written, and a failure midway ' . 'leaves the result listing assigned, unassigned and failed.',
            'To set up a project from scratch use one deck_create_board call: it creates the board, its lists and their first ' . 'cards after a single confirmation (at most ' . BoardBlueprint::MAX_STACKS . ' lists and ' . BoardBlueprint::MAX_CARDS . ' cards). ' . 'The board is open only to its owner, so share it in Deck before assigning anybody else. deck_create_stack adds a ' . 'list to an existing board. deck_delete_stack and deck_delete_board work only on something empty: they refuse, ' . 'saying how many cards are left, while any card (active or archived) remains, and what they delete goes to the Deck trash. A card created by somebody else right after the second count ' . 'they take before finishing may go to the trash with the list or board; it is recoverable there.',
            'duedate is a plain day (Y-m-d) and is read in the timezone of the account; a card description is capped ' . 'at ' . CardInput::MAX_DESCRIPTION_LENGTH . ' characters and a title at 255.',
            'deck_move_card can reorder a card within its current list or move it to another list, including one on another board. ' . 'Set stackId to the destination list; without order, the card goes to the end.',
        ];
    }
}
