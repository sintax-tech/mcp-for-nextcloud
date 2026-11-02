<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use LogicException;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\Locating\NoteNames;
use OCA\Mcp\Modules\Notes\Writing\NoteTypeBoundary;
use OCA\Mcp\Modules\Notes\Writing\NoteWrite;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Files\File;
use OCP\Files\Folder;
use Throwable;

/**
 * Backs `notes_edit` for a whole rewrite, an append, a replace and a title change.
 *
 * Every basename the call would leave behind is checked on one snapshot before the shared-folder confirmation and
 * before the first byte is written. The content step, after the partial plan is reread, takes one fresh snapshot
 * of that same set and only then stores bytes. The rename checks its destination again on its own snapshot.
 */
final class EditNoteHandler {
	/**
	 * Receives note access, the shared-folder confirmation, lock and plan state, and the file-name policy.
	 *
	 * @param NotesRepository $notes Note lookup, reads and naming.
	 * @param SharedWriteGuard $guard Confirmation a note outside the personal folder needs.
	 * @param NodeAccessInfo $accessInfo Who owns the note and who else it reaches.
	 * @param VisibilityGuard|null $visibilityGuard Administrator exclusions, null when none apply.
	 * @param LockAwareWrite|null $locks The files_lock check, null when the app is not there.
	 * @param PlanState|null $states Plan states of append and replace. Required when the call is partial.
	 * @param FileTypeGuard|null $types Policy reader. Null skips the check and is not a bypass in production.
	 * @return void
	 */
	public function __construct(
		private NotesRepository $notes,
		private SharedWriteGuard $guard,
		private NodeAccessInfo $accessInfo,
		private ?VisibilityGuard $visibilityGuard,
		private ?LockAwareWrite $locks,
		private ?PlanState $states,
		private ?FileTypeGuard $types,
	) {
	}

	/**
	 * Applies one notes_edit, or returns the shared-folder confirmation without writing.
	 *
	 * @param Folder|null $root Notes folder. Null when the user has none yet.
	 * @param string $userId Authenticated user.
	 * @param array<string, mixed> $arguments Validated notes_edit arguments.
	 * @return array<string, mixed> The note after the change, or the shared-write confirmation.
	 * @throws FileTypeDenied When the active policy refuses the current name or the renamed basename.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read. That is not treated as off.
	 * @throws LogicException When an append or a replace has no plan state. Production always has one.
	 */
	public function handle(?Folder $root, string $userId, array $arguments): array {
		$mode = NoteChange::mode($arguments);
		$note = $this->notes->find($root, $arguments['id']);
		$partial = null;
		if (NoteChange::isPartial($mode)) {
			if (!$this->states instanceof PlanState || !is_string($mode)) {
				throw new LogicException('Plan state is required for a partial note edit.');
			}
			$partial = new NoteChangeConfirmation($this->notes, $this->accessInfo, $this->states, $this->locks, $this->types);
			$content = $partial->content($root, $userId, $note, $arguments, $mode);
		} else {
			$content = isset($arguments['content']) ? (string)$arguments['content'] : null;
		}
		$write = $this->checks();
		$write->assertWritable($note, $arguments);
		$this->locks?->assertWritable($note, $userId);
		if ($content !== null) {
			NoteWrite::checkSize($content);
		}
		$rename = isset($arguments['title'])
			? (new NoteChangeTitle($this->notes))->target($note, (string)$arguments['title'])
			: null;
		$names = $this->projected($note, $content, $rename);
		$this->assertNames($names);
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		if (($payload = $this->guard->guard($note, $userId, (string)$arguments['id'], $confirmed)) !== null) {
			return $payload;
		}
		$saved = false;
		if ($content !== null) {
			$sink = new NoteContentSink($this->types);
			$operation = $partial === null || !is_string($mode)
				? $sink->whole($note, $content, $names)
				: $partial->writer($root, $userId, $note, $arguments, $mode, $content, $names);
			$write->locked($operation, $note, $userId);
			$saved = true;
		}
		if ($rename !== null) {
			try {
				$write->locked(function () use ($note, $rename): void {
					(new NoteRenameSink($this->types))->assertTarget($rename);
					$note->move($rename);
				}, $note, $userId, !$saved);
			} catch (Throwable $e) {
				throw $saved ? NoteWrite::partialEdit($e) : $e;
			}
		}
		return $write->described($root, $userId, $arguments);
	}

	/**
	 * Refuses a whole-content or title plan the write would refuse.
	 *
	 * Append and replace plans are refused by the partial plan itself. An empty name list writes nothing and does
	 * not read the policy.
	 *
	 * @param File $note Note the plan describes.
	 * @param array<string, mixed> $arguments Validated notes_edit arguments.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses a basename this edit would write or rename to.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read.
	 */
	public function assertPlanned(File $note, array $arguments): void {
		$content = isset($arguments['content']) ? (string)$arguments['content'] : null;
		$rename = isset($arguments['title'])
			? (new NoteChangeTitle($this->notes))->target($note, (string)$arguments['title'])
			: null;
		$this->assertNames($this->projected($note, $content, $rename));
	}

	/**
	 * Current basename when bytes are written, and the final basename when the title changes.
	 *
	 * @param File $note Note about to change.
	 * @param string|null $content Replacement or partial content. Null when only the title changes.
	 * @param string|null $rename Absolute destination, null when the file name stays.
	 * @return list<string> Basenames of this edit. Empty when the call writes nothing.
	 */
	private function projected(File $note, ?string $content, ?string $rename): array {
		$names = [];
		if ($content !== null) {
			$names[] = $note->getName();
		}
		if ($rename !== null) {
			$names[] = NoteNames::leaf($rename);
		}
		return $names;
	}

	/**
	 * One snapshot for every basename this edit would leave behind.
	 *
	 * @param list<string> $names Basenames from {@see self::projected()}.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses one of the names.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read.
	 */
	private function assertNames(array $names): void {
		(new NoteTypeBoundary($this->types))->assertProjected($names);
	}

	/**
	 * Builds the check and lock wrapper for this edit.
	 *
	 * @return NoteWrite Checks and lock wrapping for this edit.
	 */
	private function checks(): NoteWrite {
		return new NoteWrite($this->notes, $this->accessInfo, $this->visibilityGuard, $this->locks);
	}
}
