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
use OCA\Mcp\Modules\Notes\NoteSnapshot;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;

/**
 * The plan of `notes_delete`: the note that would go to the trash bin. A note the trash bin would not take back is
 * refused here with the same message the write gives, so the model never asks the user about a deletion the server
 * will not perform. A lock is not refused: the plan carries it as a warning.
 */
final class DeleteNotePreview {
	/**
	 * @param NotesRepository $notes note lookup
	 * @param NotesTrash $trash whether the trash bin takes the note back
	 * @param NoteSnapshot $snapshots the note as a plan shows it
	 * @param NodeAccessInfo $accessInfo who owns the note and who else it reaches
	 * @param LockAwareWrite|null $locks the files_lock check, null when the app is not there
	 */
	public function __construct(
		private NotesRepository $notes,
		private NotesTrash $trash,
		private NoteSnapshot $snapshots,
		private NodeAccessInfo $accessInfo,
		private ?LockAwareWrite $locks,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments validated tool arguments
	 * @return array<string, mixed> the note that would go to the trash bin
	 * @throws ToolFailure when the trash bin is off or would not take the note back, or the note is missing, stale
	 *   or not deletable
	 */
	public function plan(string $userId, array $arguments): array {
		$root = $this->notes->folder($userId, false);
		$this->trash->assertEnabled($userId);
		$note = $this->notes->find($root, $arguments['id']);
		NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
		if (!$note->isDeletable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$this->trash->assertTakesBack($note);
		return [
			'action' => 'notes_delete',
			'note' => $this->snapshots->of($root, $note),
			'access' => $this->accessInfo->describe($note, $userId),
			'shared' => $this->snapshots->shared($note, $userId),
			'trash' => true,
			'recoverable' => true,
			'consequence' => NotesMessages::planDeleteConsequence(),
			'message' => NotesMessages::planDelete(),
		] + ($this->locks?->planNotice($note, $userId) ?? []);
	}
}
