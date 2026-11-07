<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\NoteSnapshot;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Writing\NoteWrite;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;

/**
 * The plan of `notes_edit`. An append or a replace is planned by {@see NoteChangePreview}; a whole rewrite or a
 * title change shows the note before and after, with the same refusals the write gives, so the user is never asked
 * to approve what would fail. A lock is carried as a warning instead of a refusal.
 */
final class EditNotePreview {
	/**
	 * @param NotesRepository $notes note lookup, reads and naming
	 * @param EditNoteHandler $edits the refusals the edit itself gives before it writes
	 * @param NoteSnapshot $snapshots the note as a plan shows it
	 * @param NodeAccessInfo $accessInfo who owns the note and who else it reaches
	 * @param LockAwareWrite|null $locks the files_lock check, null when the app is not there
	 * @param PlanState|null $states the plan states of append and replace; the container always provides it
	 * @param FileTypeGuard|null $types file-name policy; null only when a unit double omits the container
	 */
	public function __construct(
		private NotesRepository $notes,
		private EditNoteHandler $edits,
		private NoteSnapshot $snapshots,
		private NodeAccessInfo $accessInfo,
		private ?LockAwareWrite $locks,
		private ?PlanState $states,
		private ?FileTypeGuard $types,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array{id:int, content?:string, append?:string, old?:string, new?:string, title?:string, etag?:string, confirm_shared?:bool} $arguments validated tool arguments
	 * @return array<string, mixed> the note before and after the change, or the plan of an append or a replace
	 * @throws ToolFailure when the note is missing, stale, not writable or over the limit, or the title is refused
	 * @throws FileTypeDenied when the active policy refuses a basename this edit would write or rename to
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 */
	public function plan(string $userId, array $arguments): array {
		$root = $this->notes->folder($userId, false);
		$mode = NoteChange::mode($arguments);
		if (NoteChange::isPartial($mode)) {
			return (new NoteChangePreview($this->notes, $this->accessInfo, $this->states, $this->locks, $this->types))->plan($root, $userId, $arguments, $mode);
		}
		$note = $this->notes->find($root, $arguments['id']);
		if (isset($arguments['content'])) {
			NoteWrite::checkSize($arguments['content']);
		}
		NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
		if (!$note->isUpdateable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		// The same refusal the write gives, so the user is never asked to approve what would fail.
		if (isset($arguments['title'])) {
			(new NoteChangeTitle($this->notes))->target($note, $arguments['title']);
		}
		$this->edits->assertPlanned($note, $arguments);
		$before = $this->snapshots->of($root, $note);
		$after = $before;
		$after['title'] = isset($arguments['title'])
			? pathinfo($this->notes->renamedName($note, $arguments['title']), PATHINFO_FILENAME)
			: $before['title'];
		// `changed` compares the whole content: the excerpts a plan shows are cut and can be equal for
		// two different notes.
		$contentChanged = false;
		if (isset($arguments['content'])) {
			$after['content'] = NoteSnapshot::excerpt($arguments['content']);
			$after['bytes'] = strlen($arguments['content']);
			$contentChanged = $arguments['content'] !== (string)$note->getContent();
		}
		return [
			'action' => 'notes_edit',
			'note' => ['id' => (int)$note->getId(), 'before' => $before, 'after' => $after],
			'changed' => array_values(array_filter(
				['title', 'content'],
				static fn (string $field): bool => $field === 'title' ? $before['title'] !== $after['title'] : $contentChanged,
			)),
			'access' => $this->accessInfo->describe($note, $userId),
			'shared' => $this->snapshots->shared($note, $userId),
			'recoverable' => true,
			'message' => NotesMessages::planEdit(),
		] + ($this->locks?->planNotice($note, $userId) ?? []);
	}
}
