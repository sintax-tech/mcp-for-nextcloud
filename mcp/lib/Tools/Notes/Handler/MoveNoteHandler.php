<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes\Handler;

use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NoteRenameSink;
use OCA\Mcp\Tools\Notes\NoteTypeBoundary;
use OCA\Mcp\Tools\Notes\NoteWrite;
use OCA\Mcp\Tools\Notes\NotesMessages;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Backs `notes_move`.
 *
 * The note keeps its extension. That basename is refused before the shared-folder confirmation and before the
 * target category is created, including when the note is already in that category. The move itself checks the
 * same basename again on a new snapshot.
 */
final class MoveNoteHandler {
	/**
	 * Receives note access, the shared-folder confirmation, the lock check and the file-name policy.
	 *
	 * @param NotesRepository $notes Note lookup and categories.
	 * @param SharedWriteGuard $guard Confirmation a note outside the personal folder needs.
	 * @param NodeAccessInfo $accessInfo Who owns the note and who else it reaches.
	 * @param VisibilityGuard|null $visibilityGuard Administrator exclusions, null when none apply.
	 * @param LockAwareWrite|null $locks The files_lock check, null when the app is not there.
	 * @param FileTypeGuard|null $types Policy reader. Null skips the check and is not a bypass in production.
	 * @return void
	 */
	public function __construct(
		private NotesRepository $notes,
		private SharedWriteGuard $guard,
		private NodeAccessInfo $accessInfo,
		private ?VisibilityGuard $visibilityGuard,
		private ?LockAwareWrite $locks,
		private ?FileTypeGuard $types,
	) {
	}

	/**
	 * Moves one note into a category, or returns the shared-folder confirmation without moving.
	 *
	 * @param Folder|null $root Notes folder. Null when the user has none yet.
	 * @param string $userId Authenticated user.
	 * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments Validated tool arguments.
	 * @return array<string, mixed> The note after the move, or the shared-write confirmation.
	 * @throws FileTypeDenied When the active policy refuses the note's basename.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read. That is not treated as off.
	 * @throws ToolFailure When the note is missing, not writable, or the title is taken in the target.
	 */
	public function handle(?Folder $root, string $userId, array $arguments): array {
		$note = $this->notes->find($root, $arguments['id']);
		$write = $this->checks();
		$write->assertWritable($note, $arguments);
		$this->locks?->assertWritable($note, $userId);
		$this->assertPlanned($note);
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		if (($payload = $this->guard->guard($note, $userId, (string)$arguments['id'], $confirmed)) !== null) {
			return $payload;
		}
		if (!$root instanceof Folder) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		$target = $this->notes->category($root, $arguments['category']);
		if ($target->getPath() !== $note->getParent()->getPath()) {
			if ($target->nodeExists($note->getName())) {
				throw new ToolFailure(NotesMessages::titleExistsInTargetCategory());
			}
			$destination = $target->getPath() . '/' . $note->getName();
			$write->locked(function () use ($note, $destination): void {
				(new NoteRenameSink($this->types))->assertTarget($destination);
				$note->move($destination);
			}, $note, $userId);
		}
		return $write->described($root, $userId, $arguments);
	}

	/**
	 * Refuses a move plan the write would refuse, before the target category is created.
	 *
	 * @param File $note Note that would move, under its current name.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses that basename.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read.
	 */
	public function assertPlanned(File $note): void {
		(new NoteTypeBoundary($this->types))->assertProjected([$note->getName()]);
	}

	/**
	 * Builds the check and lock wrapper for this move.
	 *
	 * @return NoteWrite Checks and lock wrapping for this move.
	 */
	private function checks(): NoteWrite {
		return new NoteWrite($this->notes, $this->accessInfo, $this->visibilityGuard, $this->locks);
	}
}
