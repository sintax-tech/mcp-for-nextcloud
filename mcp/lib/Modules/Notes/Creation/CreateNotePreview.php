<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Creation;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Locating\NoteCategories;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\NoteSnapshot;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Writing\NoteWrite;

/**
 * The plan of `notes_create`: the note the call would create, under the name it would get. A plan creates no
 * category folder and writes nothing; when the category does not exist yet, the plan says the write creates it.
 */
final class CreateNotePreview {
	/**
	 * @param NotesRepository $notes note lookup and naming
	 * @param CreateNoteHandler $creates the refusals the creation itself gives before it writes
	 * @param NoteCategories $categories the category folders as they are now
	 * @param NoteSnapshot $snapshots the excerpt and the sharing a plan shows
	 */
	public function __construct(
		private NotesRepository $notes,
		private CreateNoteHandler $creates,
		private NoteCategories $categories,
		private NoteSnapshot $snapshots,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array{title:string, content?:string, category?:string} $arguments validated tool arguments
	 * @return array<string, mixed> the note the call would create
	 * @throws ToolFailure when the content is over the limit or the category is below a hidden folder
	 * @throws FileTypeDenied when the active policy refuses the projected basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 * @throws InvalidArgumentException on a category with traversal or control characters
	 */
	public function plan(string $userId, array $arguments): array {
		$root = $this->notes->folder($userId, false);
		NoteWrite::checkSize((string)($arguments['content'] ?? ''));
		$this->creates->assertPlanned($arguments);
		$category = (string)($arguments['category'] ?? '');
		$shared = $this->snapshots->shared($this->notes->existingAncestor($userId, $category), $userId);
		if ($root !== null) {
			// A plan refuses what the write refuses: a new category below a hidden ancestor answers exactly
			// like one that does not exist, before anything is created.
			$this->notes->assertCategoryVisible($root, $category);
		}
		$folder = $this->categories->existing($root, $category);
		if ($root === null || $folder === null) {
			// The category folder itself does not exist yet: the write creates it, and the plan says so
			// rather than creating anything now.
			return [
				'action' => 'notes_create',
				'note' => ['title' => (string)$arguments['title'], 'category' => trim($category, '/'), 'categoryCreated' => $category !== ''],
				'shared' => $shared,
				'recoverable' => true,
				'message' => NotesMessages::planCreate(),
			];
		}
		return [
			'action' => 'notes_create',
			'note' => [
				'title' => (string)$arguments['title'],
				'fileName' => $this->notes->freeName($folder, (string)$arguments['title']),
				'category' => $this->categories->name($root, $folder),
				'categoryCreated' => false,
				'content' => NoteSnapshot::excerpt((string)($arguments['content'] ?? '')),
				'bytes' => strlen((string)($arguments['content'] ?? '')),
			],
			'shared' => $shared,
			'recoverable' => true,
			'message' => NotesMessages::planCreate(),
		];
	}
}
