<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use InvalidArgumentException;
use OCA\Mcp\Platform\Text\UniqueSnippet;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;

/**
 * The three ways notes_edit changes the text of a note, which exclude each other: `content` rewrites it whole,
 * `append` adds text at the end and `old`/`new` replaces one passage that appears exactly once.
 *
 * Append and replace are small on purpose: the caller sends only the change, and both the plan and the confirmed
 * call apply it to the note as it is at that moment. Two values tie them together: the etag, which the confirmed
 * call needs and which refuses with the current one when it is stale, and the {@see PlanState} of the content and
 * the arguments, which also catches a change Nextcloud gave no new etag (a second write within the same second).
 */
final class NoteChange {
	/** Rewrites the whole note with `content`. */
	public const CONTENT = 'content';
	/** Adds `append` at the end of the note. */
	public const APPEND = 'append';
	/** Replaces the single occurrence of `old` with `new`. */
	public const REPLACE = 'replace';
	/** Tool the {@see PlanState} of a change belongs to. */
	private const TOOL = 'notes_edit';

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * The notes_edit parameters of the two small modes, in the order the schema lists them.
	 *
	 * @return array<string, array<string, mixed>> `append`, `old` and `new`
	 */
	public static function properties(): array {
		return [
			'append' => ['type' => 'string', 'minLength' => 1, 'description' => NotesMessages::PARAM_APPEND],
			'old' => ['type' => 'string', 'minLength' => 1, 'maxLength' => UniqueSnippet::MAX_BYTES, 'description' => NotesMessages::PARAM_OLD],
			'new' => ['type' => 'string', 'description' => NotesMessages::PARAM_NEW],
		];
	}

	/**
	 * Which change the arguments ask for.
	 *
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @return string|null one of the mode constants, null when only the title changes
	 * @throws ToolFailure when more than one mode is given
	 * @throws InvalidArgumentException when old comes without new (or the reverse), or nothing changes at all
	 */
	public static function mode(array $arguments): ?string {
		$modes = array_keys(array_filter([
			self::CONTENT => isset($arguments['content']),
			self::APPEND => isset($arguments['append']),
			self::REPLACE => isset($arguments['old']) || isset($arguments['new']),
		]));
		if (count($modes) > 1) {
			throw new ToolFailure(NotesMessages::editModesExclusive());
		}
		if ($modes === [self::REPLACE] && !isset($arguments['old'], $arguments['new'])) {
			throw new InvalidArgumentException('Missing argument: ' . (isset($arguments['old']) ? 'new' : 'old'));
		}
		if ($modes === [] && !isset($arguments['title'])) {
			throw new InvalidArgumentException('Missing argument: content');
		}
		return $modes[0] ?? null;
	}

	/**
	 * @param string|null $mode what {@see self::mode()} returned
	 * @return bool whether the change is applied to the note as it is now (append or replace)
	 */
	public static function isPartial(?string $mode): bool {
		return $mode === self::APPEND || $mode === self::REPLACE;
	}

	/**
	 * The etag a confirmed append or replace applies to.
	 *
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @return string the etag the caller sent
	 * @throws ToolFailure when there is none
	 */
	public static function etag(array $arguments): string {
		if (!isset($arguments['etag']) || trim((string)$arguments['etag'], '" ') === '') {
			throw new ToolFailure(NotesMessages::editNeedsEtag());
		}
		return (string)$arguments['etag'];
	}

	/**
	 * @param File $note note about to change
	 * @param string|null $etag etag the caller read, null to skip the check (a plan without one)
	 * @throws ToolFailure when the note changed since, with the etag it has now
	 */
	public static function assertCurrent(File $note, ?string $etag): void {
		$current = (string)$note->getEtag();
		if ($etag !== null && trim($etag, '"') !== trim($current, '"')) {
			throw new ToolFailure(NotesMessages::changedSinceRead($current));
		}
	}

	/**
	 * @param string $mode APPEND or REPLACE
	 * @param string $current content of the note now, as stored
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @return string the content the change leaves
	 * @throws ToolFailure when the passage to replace is not valid UTF-8, absent or not unique, or the result is over
	 *   the size limit
	 */
	public static function apply(string $mode, string $current, array $arguments): string {
		if ($mode === self::APPEND) {
			$updated = self::appended($current, (string)$arguments['append']);
		} else {
			UniqueSnippet::assertText((string)$arguments['old']);
			$updated = UniqueSnippet::replace($current, (string)$arguments['old'], (string)$arguments['new']);
		}
		if (strlen($updated) > NotesRepository::MAX_BYTES) {
			throw new ToolFailure(NotesMessages::noteTooLarge(NotesRepository::MAX_BYTES));
		}
		return $updated;
	}

	/**
	 * The {@see PlanState} of an append or a replace: the note, the content it applies to and every argument that
	 * changes the effect. Another content, even under the same etag, or other arguments give another value.
	 *
	 * @param PlanState $states the instance's plan states
	 * @param string $uid authenticated user
	 * @param int $id note id
	 * @param string $current content of the note now, as stored
	 * @param string $mode APPEND or REPLACE
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @return string the opaque value the plan carries and the confirmed call gives back
	 */
	public static function state(PlanState $states, string $uid, int $id, string $current, string $mode, array $arguments): string {
		return $states->of(self::TOOL, $uid, [
			'id' => $id,
			'mode' => $mode,
			'content' => hash('sha256', $current),
			'append' => $arguments['append'] ?? null,
			'old' => $arguments['old'] ?? null,
			'new' => $arguments['new'] ?? null,
			'title' => $arguments['title'] ?? null,
		]);
	}

	/**
	 * Adds the text on a line of its own, in the line ending the note already uses, and keeps the note ending with
	 * a newline when it did.
	 *
	 * @param string $current content now
	 * @param string $text text to add
	 * @return string the content with the text at the end
	 */
	private static function appended(string $current, string $text): string {
		if ($current === '') {
			return $text;
		}
		$eol = self::lineEnding($current);
		$text = str_replace("\n", $eol, str_replace("\r\n", "\n", $text));
		$terminated = str_ends_with($current, "\n");
		$joined = $current . ($terminated ? '' : $eol) . $text;
		return $terminated && !str_ends_with($text, "\n") ? $joined . $eol : $joined;
	}

	/**
	 * @param string $content content of the note
	 * @return string "\r\n" when its first line ends that way, "\n" otherwise (also for a single line)
	 */
	private static function lineEnding(string $content): string {
		$position = strpos($content, "\n");
		return $position !== false && $position > 0 && $content[$position - 1] === "\r" ? "\r\n" : "\n";
	}
}
