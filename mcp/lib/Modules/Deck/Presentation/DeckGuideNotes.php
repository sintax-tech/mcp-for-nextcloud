<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;


/** Keeps the existing guide and optional native-dependency notes unchanged. */
final class DeckGuideNotes {
	/** @param \OCA\Mcp\Modules\Deck\Integration\DeckCapabilities $capabilities unchanged dependency gate */
	public function __construct(private \OCA\Mcp\Modules\Deck\Integration\DeckCapabilities $capabilities) {
	}

	/** @return list<string> unchanged guide notes in their existing order */
	public function notes(): array {
		$notes = [
			'deck_edit_card_labels changes only add/remove label IDs on a card, preserving all other labels. '
				. 'Use deck_list_labels on its board first. Preview before confirm and repeat plan_state; a changed state needs a new approval.',
			'A card is addressed by three ids: deck_list_boards gives the board, deck_list_stacks the lists of a '
				. 'board, and deck_list_cards the cards of a list. Read them in that order before writing anything.',
			'deck_read_card returns the card with its comments; deck_followup_cards lists the cards that are due or '
				. 'overdue, per board.',
			'A board owned by somebody else is refused on a write until the user has been asked and the call repeats '
				. 'with confirm_shared: true. The refusal is a normal result that says what is missing, not an error.',
			'The caller owns a created card. Explicit assignments are checked before creation and shown in the plan. '
				. 'Assignment changes during creation can produce a created card with warnings; read it before retrying in Deck. '
				. 'deck_edit_card changes assignees with assign and unassign (lists of account IDs, at most 100 each); '
				. 'an account cannot be in both, accounts are checked before anything is written, and a failure midway '
				. 'leaves the result listing assigned, unassigned and failed.',
			'To set up a project from scratch use one deck_create_board call: it creates the board, its lists and their first '
				. 'cards after a single confirmation (at most ' . \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_STACKS . ' lists and ' . \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_CARDS . ' cards). '
				. 'The board is open only to its owner, so share it in Deck before assigning anybody else. deck_create_stack adds a '
				. 'list to an existing board. deck_delete_stack and deck_delete_board work only on something empty: they refuse, '
				. 'saying how many cards are left, while any card (active or archived) remains, and what they delete goes to the Deck trash. A card created by somebody else right after the second count '
				. 'they take before finishing may go to the trash with the list or board; it is recoverable there.',
			\OCA\Mcp\Modules\Deck\DueDates\CardDueDateMessages::GUIDE_NOTE . ' A card description is capped '
				. 'at ' . \OCA\Mcp\Modules\Deck\Editing\CardInput::MAX_DESCRIPTION_LENGTH . ' characters and a title at 255.',
			'deck_move_card moves a card to another list, including one on another board. Existing permissions apply to '
				. 'both ends; a shared origin or destination requires confirm_shared: true after asking the user.',
		];
		foreach (\OCA\Mcp\Modules\Deck\Presentation\DeckGuideNotes::lines() as $note) {
			$notes[] = $note;
		}
		if ($this->capabilities->dependencies()) {
			$notes[] = \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_READ_DEPENDENCIES_DESCRIPTION;
		}
		return $notes;
	}

	/**
	 * Versions, direction, hidden targets and the limits of a file link.
	 *
	 * @return list<string> Notes appended after the existing Deck guide lines.
	 */
	public static function lines(): array {
		return [
			'Labels (deck_list_labels, deck_edit_card_labels) are available on Nextcloud 32 through 35 when Deck is enabled. Proved releases: Deck 1.16.8, 1.17.7, 1.18.5 and 1.19.0. A label from another board is refused. The write changes only the requested ids.',
			'Native card dependencies (deck_read_card_dependencies, deck_add_card_dependency, deck_remove_card_dependency) appear only on Nextcloud 34 and 35 with the native API. Proved releases: Deck 1.18.5 and 1.19.0. On Nextcloud 32 and 33 those tools are absent. Do not imitate the missing API. Direction is outgoing: cardId is the origin and targetId is the card it depends on. Self-relations and cycles in the readable graph are refused. A relation whose other card you cannot read is omitted entirely: no count, no id and no title. A cycle that would pass through such a card cannot be detected.',
			'File tools use the native Deck file share. They do not upload, copy or delete the file. deck_list_card_files and deck_unlink_card_file exist on Nextcloud 32 through 35. attachmentId is the share id and fileId is the file. Listing needs deck.read and files.read. Unlinking needs deck.edit and files.read, keeps the original file, and an editor of the card can remove a visible association created by someone else. A missing association and one you cannot see share one refusal: no count and no handle, and this app does not remove it. Tell the user to open the card in Deck, without claiming that a hidden association exists. Folders, and an archived card or board you can already see, are refused in plain language.',
			'deck_link_card_file needs three grants that already exist: deck.edit, files.read and files.share. The plan shows the file path, the owner, whether this is a reshare, the current recipient total, a limited list of names, the shares you created, and read-only access (READ=1) for current members and for people who later join the board, a group or a team. Unlinking that share removes it for everyone; a separate share can keep access. The check applies only when the link is created. A policy turned on later, a change of board, group or team membership, and the moment between the last check and the native share can change who can read the file. The plan hash does not make the share atomic and does not revoke the link afterwards. The server does not record human approval. Do not suggest turning a policy off or signing in another way.',
		];
	}
}
