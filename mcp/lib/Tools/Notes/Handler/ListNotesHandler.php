<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes\Handler;

use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Tools\Notes\NotesFolderNotice;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Backs `notes_list`: every note of the notes folder, newest first, each with who owns it and who else it reaches.
 *
 * Without a notes folder the answer is still an empty JSON list, followed by the notice of {@see NotesFolderNotice}.
 */
final class ListNotesHandler {
	/**
	 * Receives note lookup and the access description of each note.
	 *
	 * @param NotesRepository $notes Note lookup, already filtered by administrator exclusions.
	 * @param NodeAccessInfo $accessInfo Who owns a note and who else it reaches.
	 * @return void
	 */
	public function __construct(
		private NotesRepository $notes,
		private NodeAccessInfo $accessInfo,
	) {
	}

	/**
	 * Lists the notes of the account.
	 *
	 * @param Folder|null $root Notes folder, null when it does not exist.
	 * @param string $userId Authenticated user.
	 * @return array{content: list<array{type:string, text:string}>} The list, with the missing-folder notice when it applies.
	 * @throws \JsonException When the answer cannot be encoded.
	 */
	public function handle(?Folder $root, string $userId): array {
		return NotesFolderNotice::attach('notes_list', $root, ToolResult::json($this->notes($root, $userId)));
	}

	/**
	 * The notes below the root, newest first.
	 *
	 * @param Folder|null $root Notes folder, null when it does not exist.
	 * @param string $userId Authenticated user.
	 * @return list<array{id:int, title:string, category:string, modified:int, etag:string, access:array<string, mixed>}> Notes.
	 */
	private function notes(?Folder $root, string $userId): array {
		if ($root === null) {
			return [];
		}
		$notes = array_map(
			fn (File $note): array => $this->notes->info($note, $root) + ['access' => $this->accessInfo->describe($note, $userId)],
			$this->notes->all($root),
		);
		usort($notes, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
		return $notes;
	}
}
