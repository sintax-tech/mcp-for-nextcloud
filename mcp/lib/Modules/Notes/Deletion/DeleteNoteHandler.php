<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Deletion;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Integration\NotesTrash;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Writing\NoteWrite;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;

/**
 * Backs `notes_delete`: the note goes to the Nextcloud trash bin, or nothing happens. Where the trash bin would not
 * take it back, the deletion is refused instead of removing the note for good.
 */
final class DeleteNoteHandler {
	/**
	 * @param NotesRepository $notes note lookup
	 * @param NotesTrash $trash whether the trash bin takes the note back
	 * @param NoteWrite $writes the checks and the lock wrapping every note write shares
	 * @param SharedWriteGuard $guard the confirmation a note outside the personal folder needs
	 * @param LockAwareWrite|null $locks the files_lock check, null when the app is not there
	 */
	public function __construct(
		private NotesRepository $notes,
		private NotesTrash $trash,
		private NoteWrite $writes,
		private SharedWriteGuard $guard,
		private ?LockAwareWrite $locks,
	) {
	}

	/**
	 * Moves one note to the trash bin, or returns the shared-write confirmation without deleting.
	 *
	 * @param string $userId authenticated user
	 * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments validated tool arguments
	 * @return array<string, mixed> the deletion receipt, or the shared-write confirmation without deleting
	 * @throws ToolFailure when the trash bin is off or would not take the note back, or the note is missing,
	 *   not writable, not deletable, locked or changed since the caller read it
	 */
	public function handle(string $userId, array $arguments): array {
		$root = $this->notes->folder($userId, false);
		$this->trash->assertEnabled($userId);
		$note = $this->notes->find($root, $arguments['id']);
		$this->writes->assertWritable($note, $arguments);
		// Before the shared-write confirmation, so nobody is asked to approve a write the lock refuses; and before a
		// delete, whose hooks in Text could reset the document of the open editor.
		$this->locks?->assertWritable($note, $userId);
		if (($payload = $this->guard->guard($note, $userId, (string)$arguments['id'], (bool)($arguments['confirm_shared'] ?? false))) !== null) {
			return $payload;
		}
		$note = $this->notes->find($root, $arguments['id']);
		if (!$note->isDeletable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$this->trash->assertTakesBack($note);
		$this->writes->locked(fn () => $note->delete(), $note, $userId);
		return ['id' => $arguments['id'], 'deleted' => true, 'trash' => true];
	}
}
