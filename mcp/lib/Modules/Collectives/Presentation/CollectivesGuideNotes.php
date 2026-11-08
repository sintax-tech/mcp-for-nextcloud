<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;
use OCA\Mcp\Modules\Collectives\Removal\RemovalReadback;

/** What the Collectives schemas cannot say, for mcp_guide: how pages are addressed and what the module refuses. */
final class CollectivesGuideNotes {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @return list<string> behaviour notes of the Collectives tools, in English
	 */
	public static function notes(): array {
		return [
			'A page is addressed by the collective_id of collectives_list and the page_id of collectives_pages or collectives_search, '
				. 'never by its title or path. The landing page of a collective is the page whose parent_id is 0.',
			'Pages are Markdown files of at most ' . CollectivesMarkdownPolicy::MAX_BYTES . ' bytes; templates, attachments '
				. 'and pages hidden by administrator policy appear as if they did not exist.',
			'collectives_search with scope content uses the Collectives search index, which may lag behind recent changes; '
				. 'an empty answer does not prove there is no match.',
			'Listings return next_cursor while more items remain; pass it back unchanged with the same arguments.',
			'collectives_create makes an empty page; when the parent has no subpages yet, Collectives turns it into a folder '
				. 'with a Readme.md (its id stays). Add the content with collectives_edit.',
			'For a small change use collectives_edit with append or old/new; content rewrites the whole page. Confirm with the '
				. 'etag and plan_state of the plan. Someone editing the page in the browser at the same time may write over the change.',
			'collectives_move keeps the current parent when parent_id is omitted; to move a page to the top level, give the id of '
				. 'the landing page. index only places the page under a new parent: reordering under the same parent is not supported. '
				. 'The landing page cannot move, nor can a page move under itself or into another collective.',
			'collectives_create_collective creates a collective with its own new team, owned by the user and with no other member. '
				. 'It refuses a name already used by a collective or team the user sees, and it needs Nextcloud 33 or later.',
			'Members are managed by admins of the collective: collectives_list_members gives the member_id, then collectives_add_member '
				. '(an account found with Search people, or a group), collectives_set_member_level (member, moderator or admin) and '
				. 'collectives_remove_member. The owner cannot be changed or removed, and your own membership is not changed here.',
			'Teams applies a removal in the background, so collectives_remove_member reads the team again for up to '
				. RemovalReadback::SECONDS . ' seconds. A receipt with status uncertain and membership_changed false means the member '
				. 'was still listed then; with membership_changed unknown the team could not be read again. Neither is a reason to '
				. 'send the removal again: check collectives_list_members first.',
		];
	}
}
