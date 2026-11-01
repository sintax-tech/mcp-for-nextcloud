<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * A confirmed append or replace writes only what its plan showed. It needs the etag and the `plan_state` of that
 * plan: a stale etag is refused with the current one, and a note whose content (or a call whose arguments) differs
 * from the approved state answers with the new plan instead ({@see PlanChanged}), nothing written. The content is
 * compared, not only the etag, because Nextcloud keeps the etag of a file written twice within the same second.
 *
 * The state is checked when the call arrives and again right before the write, inside the lock check. What is left
 * is the gap between that last read and putContent() in the same request: Nextcloud has no compare-and-swap for a
 * file, so a write by somebody else landing in that gap (no files_lock lock held) is still overwritten.
 */
final class NoteChangeConfirmation {
	private NoteChangePreview $preview;

	/**
	 * @param NotesRepository $notes note lookup and reads
	 * @param NodeAccessInfo $accessInfo who owns the note, for the new plan
	 * @param PlanState $states the instance's plan states
	 * @param LockAwareWrite|null $locks the files_lock check, for the new plan
	 * @param FileTypeGuard|null $types file-name policy checked again beside the write; null only outside production
	 */
	public function __construct(
		private NotesRepository $notes,
		NodeAccessInfo $accessInfo,
		private PlanState $states,
		?LockAwareWrite $locks = null,
		private ?FileTypeGuard $types = null,
	) {
		$this->preview = new NoteChangePreview($notes, $accessInfo, $states, $locks, $types);
	}

	/**
	 * @param Folder|null $root notes folder
	 * @param string $userId authenticated user
	 * @param File $note the note the call changes
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @param string $mode NoteChange::APPEND or NoteChange::REPLACE
	 * @return string the content to write
	 * @throws ToolFailure without an etag, with a stale one, or when the change cannot be applied
	 * @throws ArgumentValidationException without `plan_state`
	 * @throws PlanChanged when the note or the arguments are not the ones the plan showed
	 */
	public function content(?Folder $root, string $userId, File $note, array $arguments, string $mode): string {
		NoteChange::assertCurrent($note, NoteChange::etag($arguments));
		PlanState::require($arguments);
		$current = $this->notes->raw($note);
		$this->assertApproved($root, $userId, $note, $current, $arguments, $mode);
		return NoteChange::apply($mode, $current, $arguments);
	}

	/**
	 * The write itself, for the module to run inside its lock check: the note is read once more right before
	 * putContent(), so a change since {@see self::content()} is not overwritten.
	 *
	 * @param Folder|null $root notes folder
	 * @param string $userId authenticated user
	 * @param File $note the note about to be written
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @param string $mode NoteChange::APPEND or NoteChange::REPLACE
	 * @param string $content what {@see self::content()} returned
	 * @param list<string> $basenames current name and, when the title changes, the final basename; one snapshot covers
	 *   the set after the recheck and before the bytes are stored
	 * @return \Closure():void the write; it throws {@see PlanChanged} when the note is no longer the approved one, and
	 *   then nothing is written
	 */
	public function writer(?Folder $root, string $userId, File $note, array $arguments, string $mode, string $content, array $basenames): \Closure {
		return (new NoteContentSink($this->types))->partial(
			$note,
			$content,
			function () use ($root, $userId, $note, $arguments, $mode): void {
				$this->assertApproved($root, $userId, $note, $this->notes->raw($note), $arguments, $mode);
			},
			$basenames,
		);
	}

	/**
	 * @param Folder|null $root notes folder
	 * @param string $userId authenticated user
	 * @param File $note the note
	 * @param string $current its content as just read
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @param string $mode NoteChange::APPEND or NoteChange::REPLACE
	 * @throws PlanChanged with the plan of the state found, when it is not the approved one
	 */
	private function assertApproved(?Folder $root, string $userId, File $note, string $current, array $arguments, string $mode): void {
		$state = NoteChange::state($this->states, $userId, (int)$note->getId(), $current, $mode, $arguments);
		if (!PlanState::matches($state, $arguments)) {
			throw new PlanChanged($this->preview->plan($root, $userId, $arguments, $mode, true));
		}
	}
}
