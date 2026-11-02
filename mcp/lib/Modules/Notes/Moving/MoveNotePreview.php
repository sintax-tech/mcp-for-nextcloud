<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Moving;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Locating\NoteCategories;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\NoteSnapshot;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * The plan of `notes_move`: where the note goes, whether that category would be created and whether the title is
 * already taken there. A target below a hidden ancestor is refused exactly like one that does not exist, and a lock
 * is carried as a warning instead of a refusal.
 */
final class MoveNotePreview {
	/**
	 * @param NotesRepository $notes note lookup
	 * @param MoveNoteHandler $moves the refusals the move itself gives before it writes
	 * @param NoteCategories $categories the category folders as they are now
	 * @param NoteSnapshot $snapshots the note as a plan shows it
	 * @param NodeAccessInfo $accessInfo who owns the note and who else it reaches
	 * @param VisibilityGuard|null $visibilityGuard administrator exclusions, null when none apply
	 * @param LockAwareWrite|null $locks the files_lock check, null when the app is not there
	 */
	public function __construct(
		private NotesRepository $notes,
		private MoveNoteHandler $moves,
		private NoteCategories $categories,
		private NoteSnapshot $snapshots,
		private NodeAccessInfo $accessInfo,
		private ?VisibilityGuard $visibilityGuard,
		private ?LockAwareWrite $locks,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments validated tool arguments
	 * @return array<string, mixed> where the note goes
	 * @throws ToolFailure when the note is missing, stale or not writable, or the target is hidden
	 * @throws FileTypeDenied when the active policy refuses the note's basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 * @throws InvalidArgumentException on a category with traversal or control characters
	 */
	public function plan(string $userId, array $arguments): array {
		$root = $this->notes->folder($userId, false);
		$note = $this->notes->find($root, $arguments['id']);
		NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
		if (!$note->isUpdateable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$this->moves->assertPlanned($note);
		$category = (string)$arguments['category'];
		if ($root !== null) {
			// The same refusal the write gives: a target below a hidden ancestor is a target that does not
			// exist, so the user is never asked to approve a move the server would not perform.
			$this->notes->assertCategoryVisible($root, $category);
		}
		$folder = $this->categories->existing($root, $category);
		return [
			'action' => 'notes_move',
			'note' => $this->snapshots->of($root, $note),
			'from' => $this->snapshots->of($root, $note)['category'],
			'to' => trim($category, '/'),
			'categoryCreated' => $folder === null && trim($category, '/') !== '',
			'titleTaken' => $this->titleTaken($folder, $note),
			'access' => $this->accessInfo->describe($note, $userId),
			'shared' => $this->snapshots->shared($note, $userId),
			'recoverable' => true,
			'message' => NotesMessages::planMove(),
		] + ($this->locks?->planNotice($note, $userId) ?? []);
	}

	/**
	 * @param Folder|null $folder the target category as it is now, null when the move would create it
	 * @param File $note the note that would move
	 * @return bool whether a note the user can see already has that name in the target
	 */
	private function titleTaken(?Folder $folder, File $note): bool {
		if ($folder === null || !$folder->nodeExists($note->getName())) {
			return false;
		}
		$existing = $folder->get($note->getName());
		return $this->visibilityGuard === null || $this->visibilityGuard->isVisible($existing);
	}
}
