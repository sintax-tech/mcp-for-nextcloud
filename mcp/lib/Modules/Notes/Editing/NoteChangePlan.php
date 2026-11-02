<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Platform\Text\UnifiedDiff;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;

/**
 * The plan of an append or a replace: sizes, the text the call sends, a bounded diff of the change and the etag to
 * confirm with. The note's content never goes into it, so a small change stays small in the answer too.
 */
final class NoteChangePlan {
	/** Characters of diff the plan carries: the changed passage and its context, never the note. */
	public const DIFF_CHARS = 2000;
	/** Characters of the appended or replaced text the plan repeats for the person. */
	private const TEXT_CHARS = 400;

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * @param string $mode NoteChange::APPEND or NoteChange::REPLACE
	 * @param array{id:int, title:string, category:string, etag:string} $note the note as NotesRepository::info() describes it
	 * @param string $title title the note ends up with
	 * @param string $current content now
	 * @param string $updated content after the change
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @return array<string, mixed> the mode-specific part of the plan
	 */
	public static function of(string $mode, array $note, string $title, string $current, string $updated, array $arguments): array {
		$before = ['title' => $note['title'], 'category' => $note['category'], 'bytes' => strlen($current)];
		$after = ['title' => $title, 'category' => $note['category'], 'bytes' => strlen($updated)];
		return [
			'action' => 'notes_edit',
			'mode' => $mode,
			'note' => ['id' => $note['id'], 'before' => $before, 'after' => $after],
			'change' => $mode === NoteChange::APPEND
				? ['append' => self::cut((string)$arguments['append'])]
				: ['old' => self::cut((string)$arguments['old']), 'new' => self::cut((string)$arguments['new'])],
			'changed' => array_keys(array_filter(['title' => $note['title'] !== $title, 'content' => $current !== $updated])),
			'diff' => self::diff($current, $updated),
			'etag' => $note['etag'],
			'message' => $mode === NoteChange::APPEND ? NotesMessages::planAppend() : NotesMessages::planReplace(),
		];
	}

	/**
	 * @param string $current content now
	 * @param string $updated content after the change
	 * @return string the unified diff of the change, cut at DIFF_CHARS
	 */
	public static function diff(string $current, string $updated): string {
		$diff = UnifiedDiff::between($current, $updated);
		return mb_strlen($diff) > self::DIFF_CHARS
			? mb_substr($diff, 0, self::DIFF_CHARS) . "\n" . UnifiedDiff::TRUNCATED
			: $diff;
	}

	/**
	 * @param string $text text the caller sent
	 * @return string its beginning, marked when there is more
	 */
	private static function cut(string $text): string {
		return mb_strlen($text) > self::TEXT_CHARS
			? mb_substr($text, 0, self::TEXT_CHARS) . NotesMessages::excerptTruncated()
			: $text;
	}
}
