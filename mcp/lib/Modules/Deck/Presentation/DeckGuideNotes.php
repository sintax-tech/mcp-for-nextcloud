<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

/**
 * English notes for the Deck module of mcp_guide.
 *
 * The guide is read by the model. What the user sees stays in the translated message classes.
 */
final class DeckGuideNotes {
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
