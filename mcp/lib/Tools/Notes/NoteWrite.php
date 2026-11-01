<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use Throwable;

/**
 * Checks and lock wrapping shared by note writes.
 *
 * Create, edit, move and the module's delete path all use this implementation. The module methods that remain
 * only delegate here.
 */
final class NoteWrite {
	/**
	 * Receives note lookup, access description, administrator exclusions and the lock check.
	 *
	 * @param NotesRepository $notes Note lookup used when describing the result.
	 * @param NodeAccessInfo $accessInfo Who owns the note and who else it reaches.
	 * @param VisibilityGuard|null $visibilityGuard Administrator exclusions, null when none apply.
	 * @param LockAwareWrite|null $locks The files_lock check, null when the app is not there.
	 * @return void
	 */
	public function __construct(
		private NotesRepository $notes,
		private NodeAccessInfo $accessInfo,
		private ?VisibilityGuard $visibilityGuard,
		private ?LockAwareWrite $locks,
	) {
	}

	/**
	 * The checks every note write shares, before anything is asked of the user.
	 *
	 * @param File $note The note about to change.
	 * @param array{etag?:string} $arguments Validated tool arguments.
	 * @return void
	 * @throws ToolFailure When the note cannot be updated or changed since the caller read it.
	 */
	public function assertWritable(File $note, array $arguments): void {
		if (!$note->isUpdateable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
	}

	/**
	 * Runs a write on a note, explaining a lock refusal of the storage when the lock appeared after the check.
	 *
	 * @template T
	 * @param callable():T $write The write.
	 * @param File $note The note it changes.
	 * @param string $userId Authenticated user.
	 * @param bool $untouched False when an earlier write of the same call already changed the note.
	 * @return T What the write returned.
	 */
	public function locked(callable $write, File $note, string $userId, bool $untouched = true): mixed {
		return NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->run($write, $note, $userId, null, true, $untouched), $this->visibilityGuard);
	}

	/**
	 * The note as the other write tools return it, read again after the change.
	 *
	 * @param Folder|null $root Notes folder.
	 * @param string $userId Authenticated user.
	 * @param array{id:int} $arguments Validated tool arguments.
	 * @return array<string, mixed> Identity, category and access of the note.
	 * @throws ToolFailure When the note can no longer be found.
	 */
	public function described(?Folder $root, string $userId, array $arguments): array {
		$note = $this->notes->find($root, $arguments['id']);
		return $this->notes->info($note, $root) + ['access' => $this->accessInfo->describe($note, $userId)];
	}

	/**
	 * Refuses content over the note limit before any file is created or rewritten.
	 *
	 * @param string $content Full content of a note.
	 * @return void
	 * @throws ToolFailure When the content is over the note limit.
	 */
	public static function checkSize(string $content): void {
		if (strlen($content) > NotesRepository::MAX_BYTES) {
			throw new ToolFailure(NotesMessages::noteTooLarge(NotesRepository::MAX_BYTES));
		}
	}

	/**
	 * The refusal of a rename that came after the new content of the same edit was saved, saying so.
	 *
	 * A client-safe failure keeps its type and lock data and gains the sentence. A Nextcloud file exception
	 * is first mapped as everywhere else. Anything else is unexpected and stays as it is.
	 *
	 * @param Throwable $e What refused the rename.
	 * @return Throwable The failure to throw.
	 */
	public static function partialEdit(Throwable $e): Throwable {
		try {
			NodeAccess::run(static fn () => throw $e);
		} catch (ToolFailure $mapped) {
			return $mapped instanceof LockWriteFailure
				? $mapped->withNote(NotesMessages::contentSavedTitleNot())
				: new ToolFailure($mapped->getMessage() . ' ' . NotesMessages::contentSavedTitleNot(), 0, $mapped);
		} catch (Throwable) {
		}
		return $e;
	}
}
