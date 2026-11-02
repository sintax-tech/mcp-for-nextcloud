<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;

/** Backs `notes_read`: one note, whole, with where it is and who else it reaches. */
final class ReadNoteHandler {
	/**
	 * @param NotesRepository $notes note lookup and reads, already filtered by administrator exclusions
	 * @param NodeAccessInfo $accessInfo who owns a note and who else it reaches
	 */
	public function __construct(
		private NotesRepository $notes,
		private NodeAccessInfo $accessInfo,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array{id:int} $arguments validated tool arguments
	 * @return array{id:int, title:string, category:string, modified:int, etag:string, path:string, content:string, access:array<string, mixed>}
	 * @throws ToolFailure when the note is missing, hidden or over the read limit
	 */
	public function handle(string $userId, array $arguments): array {
		$root = $this->notes->folder($userId, false);
		$note = $this->notes->find($root, $arguments['id']);
		return $this->notes->info($note, $root) + [
			'path' => $this->notes->path($note, $userId),
			'content' => $this->notes->read($note),
			'access' => $this->accessInfo->describe($note, $userId),
		];
	}
}
