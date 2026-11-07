<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Reading;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Resources\NoteResource;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;

/**
 * Reads the note behind an `nc://notes/{id}` resource. The repository it gets looks notes up without the
 * administrator exclusions, and the exclusions are then asserted on the note found: the resource keeps the plain
 * guard and the answer it has always given (D4).
 */
final class NoteResourceReader implements NoteResource {
	/**
	 * @param NotesRepository $notes note lookup and reads, built without the visibility guard
	 * @param VisibilityGuard $visibilityGuard administrator exclusions, asserted on the note found
	 */
	public function __construct(
		private NotesRepository $notes,
		private VisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function read(string $userId, int $id): string {
		$notesFolder = $this->notes->folder($userId, false);
		$note = $this->notes->find($notesFolder, $id);
		$this->visibilityGuard->assertVisible($note);
		return $this->notes->read($note);
	}
}
