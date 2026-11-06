<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;

/**
 * The bytes files_replace searches, taken only after the reported size has been checked against the same
 * ceiling files_read already uses ({@see TextExtractor::MAX_BYTES}).
 *
 * The plan and the confirmed write each call {@see self::read()} on their own, so a file that grows past the
 * ceiling between the two is refused on the second call and nothing is written.
 *
 * A negative size is not a length. Nextcloud reports {@see \OCP\Files\FileInfo::SPACE_NOT_COMPUTED} (-1),
 * {@see \OCP\Files\FileInfo::SPACE_UNKNOWN} (-2) and {@see \OCP\Files\FileInfo::SPACE_UNLIMITED} (-3) that way. getContent() would
 * buffer the whole object, and {@see TextExtractor::read()} would only cap what this process keeps: the
 * storage can still send the rest. The backup then compares that reported size with the copy, so a negative
 * size cannot be shown to fit and is refused before getContent() and before fopen(). The refusal uses the
 * same read-limit message as a file over the ceiling. It does not mean every negative answer was measured.
 *
 * A finite size within the ceiling is read with getContent(), then measured again. A storage that reported a
 * small size and returned more is refused. That second check cannot undo the read, so a lying size is not
 * covered by the ceiling. A size already over the ceiling never reaches getContent() or fopen().
 */
final class ReplaceSource {
	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * @param File $file text file whose raw bytes the snippet is applied to
	 * @return string the file as stored, at most {@see TextExtractor::MAX_BYTES} bytes
	 * @throws ToolFailure when the reported size is not a finite length within the ceiling, or getContent()
	 *   returns more than that ceiling
	 */
	public static function read(File $file): string {
		$size = $file->getSize();
		if (!is_int($size) && !is_float($size)) {
			throw new ToolFailure(TextExtractor::tooLarge());
		}
		if (!is_finite($size) || $size < 0 || $size > TextExtractor::MAX_BYTES) {
			throw new ToolFailure(TextExtractor::tooLarge());
		}
		$raw = (string)$file->getContent();
		if (strlen($raw) > TextExtractor::MAX_BYTES) {
			throw new ToolFailure(TextExtractor::tooLarge());
		}
		return $raw;
	}
}
