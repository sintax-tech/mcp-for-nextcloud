<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes\Handler;

use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NoteNames;
use OCA\Mcp\Tools\Notes\NoteTypeBoundary;
use OCA\Mcp\Tools\Notes\NoteWrite;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Backs `notes_create`.
 *
 * The projected basename is refused before any folder exists. The name `freeName` actually picks is checked again
 * on a new snapshot in the same step as the file is created. A refusal leaves no note and no category. A folder
 * created by an earlier step of the same call is not deleted when a later snapshot refuses: nothing here removes
 * a file or a folder.
 */
final class CreateNoteHandler {
	/**
	 * Receives note naming, the shared-folder confirmation, administrator exclusions and the file-name policy.
	 *
	 * @param NotesRepository $notes Note lookup and naming. The notes folder is created only after the preflight.
	 * @param SharedWriteGuard $guard Confirmation a note outside the personal folder needs.
	 * @param VisibilityGuard|null $visibilityGuard Administrator exclusions, null when none apply.
	 * @param FileTypeGuard|null $types Policy reader. Null skips the check and is not a bypass in production.
	 * @return void
	 */
	public function __construct(
		private NotesRepository $notes,
		private SharedWriteGuard $guard,
		private ?VisibilityGuard $visibilityGuard,
		private ?FileTypeGuard $types,
	) {
	}

	/**
	 * Creates one note, or returns the shared-folder confirmation without creating anything.
	 *
	 * @param string $userId Authenticated user.
	 * @param array{title:string, content?:string, category?:string, confirm_shared?:bool} $arguments Validated tool arguments.
	 * @return array<string, mixed> The new note, or the shared-write confirmation.
	 * @throws FileTypeDenied When the active policy refuses the projected or the final basename.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read. That is not treated as off.
	 * @throws ToolFailure When the content is over the limit or the notes folder cannot be created.
	 */
	public function handle(string $userId, array $arguments): array {
		$content = (string)($arguments['content'] ?? '');
		$category = (string)($arguments['category'] ?? '');
		$title = (string)$arguments['title'];
		NoteWrite::checkSize($content);
		$this->assertPlanned($arguments);
		$ancestor = $this->notes->existingAncestor($userId, $category);
		$resource = trim($category, '/') === '' ? '/' : trim($category, '/');
		if (($payload = $this->guard->guard($ancestor, $userId, $resource, (bool)($arguments['confirm_shared'] ?? false))) !== null) {
			return $payload;
		}
		$root = $this->notes->folder($userId, true);
		if (!$root instanceof Folder) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		$folder = $this->notes->category($root, $category);
		$created = NodeAccess::mutate(function () use ($folder, $title, $content): File {
			$actual = $this->notes->freeName($folder, $title);
			if ($this->types !== null) {
				$this->types->snapshot()->assertWritableName($actual);
			}
			return $folder->newFile($actual, $content);
		}, $this->visibilityGuard);
		if (!$created instanceof File) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		return $this->notes->info($created, $root);
	}

	/**
	 * Refuses a create plan the write would refuse, without creating a folder.
	 *
	 * The basename is the sanitized title plus `.md`. A collision suffix keeps that extension, so this name and the
	 * name checked beside the file agree on the type. An empty sanitized title is refused here, including when the
	 * category does not exist yet.
	 *
	 * @param array{title?:string} $arguments Validated tool arguments.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses the projected basename.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read.
	 */
	public function assertPlanned(array $arguments): void {
		(new NoteTypeBoundary($this->types))->assertProjected([
			NoteNames::created((string)($arguments['title'] ?? '')),
		]);
	}
}
