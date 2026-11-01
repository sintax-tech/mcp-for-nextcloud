<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

/**
 * Refuses JavaScript module words in a bundle fragment.
 *
 * Bundle sources may not contain `import`, `export` or `require` as a whole word anywhere in the file, not even
 * in a comment, a string, a template or a regex. The check is the text only: it does not parse JavaScript.
 * `exports` is not `export`, so `module.exports` passes, and so does a longer identifier such as
 * `importantValue` or `exported`.
 */
final class JsModuleSyntax {
	/** Identifiers that would change the fragment from a classic script into a module or a CommonJS load. */
	private const WORDS = 'import|export|require';

	/**
	 * The first forbidden identifier, and the 1-based line it stands on.
	 *
	 * @param string $source the fragment as stored on disk
	 * @return array{0: string, 1: int}|null the word and its line, or null when the fragment may ship
	 */
	public static function violation(string $source): ?array {
		$found = preg_match(
			'/(?<![A-Za-z0-9_$])(' . self::WORDS . ')(?![A-Za-z0-9_$])/',
			$source,
			$match,
			PREG_OFFSET_CAPTURE,
		);
		if ($found !== 1) {
			return null;
		}
		$offset = $match[1][1];
		return [$match[1][0], 1 + substr_count(substr($source, 0, $offset), "\n")];
	}
}
