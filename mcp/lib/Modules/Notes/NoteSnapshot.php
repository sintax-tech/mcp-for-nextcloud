<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * A note as a write plan shows it: where it is, an excerpt of what it says, and who else it reaches. The create,
 * edit, move and delete plans share it, so a person reads every plan of a note the same way.
 */
final class NoteSnapshot {
	/** Characters of the content a plan shows, so the user reads the note and not a wall of text. */
	private const EXCERPT_CHARS = 400;

	/**
	 * @param NotesRepository $notes note lookup and naming
	 * @param NodeAccessInfo $accessInfo who owns a note and who else it reaches
	 */
	public function __construct(
		private NotesRepository $notes,
		private NodeAccessInfo $accessInfo,
	) {
	}

	/**
	 * The note as a plan shows it, without reading it whole.
	 *
	 * @param Folder $root notes folder
	 * @param File $note note to describe
	 * @return array<string, mixed> identity, category, excerpt and size of the note
	 * @throws ToolFailure when the note is over the read limit
	 */
	public function of(Folder $root, File $note): array {
		$size = (int)$note->getSize();
		if ($size > NotesRepository::MAX_BYTES) {
			throw new ToolFailure(NotesMessages::noteTooLargeForReading(NotesRepository::MAX_BYTES));
		}
		return $this->notes->info($note, $root) + [
			'content' => self::excerpt((string)$note->getContent()),
			'bytes' => $size,
		];
	}

	/**
	 * @param Node $note note about to be changed, or the folder a new note would go into
	 * @param string $userId authenticated user
	 * @return list<array<string, mixed>> the ownership description when the note reaches other people, else empty
	 */
	public function shared(Node $note, string $userId): array {
		$access = $this->accessInfo->describe($note, $userId);
		return $access['scope'] === NodeAccessInfo::PERSONAL ? [] : [$access];
	}

	/**
	 * @param string $content full content of a note
	 * @return string the beginning of it, marked when there is more
	 */
	public static function excerpt(string $content): string {
		return mb_strlen($content) > self::EXCERPT_CHARS
			? mb_substr($content, 0, self::EXCERPT_CHARS) . NotesMessages::excerptTruncated()
			: $content;
	}
}
