<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Presentation;

use OCA\Mcp\Modules\Notes\Locating\NotesRepository;

/** What the Notes schemas cannot say, for mcp_guide: how a note is named and what the module refuses to do. */
final class NotesGuideNotes {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @return list<string> behaviour notes of the Notes tools, in English
	 */
	public static function notes(): array {
		return [
			'A note is addressed by the `id` notes_list or notes_search returns, not by its title; the title is the file name and '
				. 'the category is the subfolder it sits in.',
			'Use notes_search to find notes by keyword in title or Markdown content.',
			'notes_create never overwrites: when the title is taken in that category it picks a free name, and the '
				. 'response says which name it used.',
			'notes_delete only deletes where the Nextcloud trash bin takes the note back; anywhere else it refuses '
				. 'instead of removing it for good.',
			'Notes are capped at ' . NotesRepository::MAX_BYTES . ' bytes read or written, and a title at 200 characters.',
			'Pass `etag` to make a change fail instead of overwriting somebody else\'s version of the same note.',
			'For a small change use notes_edit with append or old/new: only the change is sent. Use content only to rewrite the whole note.',
			'Some notes or categories may be hidden by administrator policy and will appear as if they do not exist.',
		];
	}
}
