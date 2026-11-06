<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;

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
		];
	}
}
