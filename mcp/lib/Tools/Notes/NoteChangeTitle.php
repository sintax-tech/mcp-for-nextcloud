<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use InvalidArgumentException;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;

/**
 * The rename a new title stands for, checked before anything is written: the plan and the write of notes_edit, in
 * every mode, refuse the same titles for the same reasons.
 */
final class NoteChangeTitle {
	/**
	 * @param NotesRepository $notes how a title becomes a file name
	 */
	public function __construct(private NotesRepository $notes) {
	}

	/**
	 * Where a note goes when it gets a new title.
	 *
	 * @param File $note note being renamed
	 * @param string $title raw new title
	 * @return string|null the new path, null when the name does not change
	 * @throws InvalidArgumentException when nothing usable is left of the title
	 * @throws ToolFailure when the rename is not permitted or another note in the category already has that name
	 */
	public function target(File $note, string $title): ?string {
		$name = $this->notes->renamedName($note, $title);
		if ($name === $note->getName()) {
			return null;
		}
		$parent = $note->getParent();
		// A new title is a move inside the folder, and Nextcloud allows a move only with update on the
		// node, delete at the source and create at the destination (the same folder).
		if (!$note->isUpdateable() || !$note->isDeletable() || !$parent->isCreatable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		if ($parent->nodeExists($name)) {
			throw new ToolFailure(NotesMessages::titleExistsInCategory());
		}
		return $parent->getPath() . '/' . $name;
	}

	/**
	 * @param File $note note the call changes
	 * @param string|null $title raw new title, null when the call keeps it
	 * @return string the title the note ends up with, after the same checks as {@see self::target()}
	 * @throws InvalidArgumentException|ToolFailure as {@see self::target()}
	 */
	public function after(File $note, ?string $title): string {
		return $title !== null && $this->target($note, $title) !== null
			? pathinfo($this->notes->renamedName($note, $title), PATHINFO_FILENAME)
			: pathinfo($note->getName(), PATHINFO_FILENAME);
	}
}
