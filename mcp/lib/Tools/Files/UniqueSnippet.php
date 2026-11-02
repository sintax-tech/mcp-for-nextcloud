<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Text\SnippetMessages;

/**
 * The single-occurrence replacement that files_replace and notes_edit share: the snippet is located byte for byte
 * and must appear exactly once, or nothing is replaced and the refusal says why, so the caller sends a longer one.
 *
 * Overlapping matches count ("ana" appears twice in "banana"): a snippet that matches at two offsets does not say
 * which one the caller meant, even when the matches share bytes.
 *
 * The snippet is at most MAX_BYTES, which is what a small change needs, and both searches stop at the second match:
 * - up to SHORT_BYTES, strpos(): fast in practice, and quadratic in the worst case only up to the snippet's length
 *   (a near-match of 64 bytes in 10 MiB of the same byte took 0.29 s here); no memory beyond the content;
 * - above it, Knuth-Morris-Pratt: linear in the content plus the snippet whatever they hold. strpos() is not: a
 *   near-match of 128 KiB in 256 KiB took it 49 s, and a plan can ask for that. Its prefix table holds one PHP integer
 *   per byte of the snippet, about 16 bytes each, so at most about 1 MiB with MAX_BYTES.
 * A snippet longer than the content is absent without any search.
 */
final class UniqueSnippet {
	/** Longest snippet in bytes; the schemas declare it as maxLength too, which counts characters. */
	public const MAX_BYTES = 65536;
	/** Longest snippet in bytes that strpos() searches; longer ones go through Knuth-Morris-Pratt. */
	private const SHORT_BYTES = 64;

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * @param string $old snippet the caller wants replaced
	 * @throws ToolFailure when it is not valid UTF-8, so it could never be located as text, or over MAX_BYTES (the
	 *   schema bounds characters, and a multibyte snippet can pass that bound with more bytes)
	 */
	public static function assertText(string $old): void {
		if (!mb_check_encoding($old, 'UTF-8')) {
			throw new ToolFailure(SnippetMessages::notUtf8());
		}
		if (strlen($old) > self::MAX_BYTES) {
			throw new ToolFailure(Translator::t('The specified snippet is longer than %d bytes; send a shorter, unique snippet.', [self::MAX_BYTES]));
		}
	}

	/**
	 * @param string $content current content, as stored
	 * @param string $old snippet that must appear exactly once; never empty (the schemas say minLength 1)
	 * @param string $new replacement text
	 * @return string the content with that single occurrence replaced, every other byte untouched
	 * @throws ToolFailure when the snippet is not valid UTF-8 or over MAX_BYTES ({@see self::assertText()}), absent, or
	 *   appears more than once, overlapping occurrences included
	 */
	public static function replace(string $content, string $old, string $new): string {
		self::assertText($old);
		$matches = self::firstTwo($content, $old);
		if ($matches === []) {
			throw new ToolFailure(SnippetMessages::missing());
		}
		if (count($matches) > 1) {
			throw new ToolFailure(SnippetMessages::ambiguous());
		}
		// Only the offset just proven unique is replaced.
		return substr_replace($content, $new, $matches[0], strlen($old));
	}

	/**
	 * The two searches the class describes: strpos() for a short snippet, Knuth-Morris-Pratt for a long one.
	 *
	 * @param string $content content to search
	 * @param string $old snippet, never empty, at most MAX_BYTES
	 * @return list<int> the offsets of its first two matches, overlapping ones included; fewer when there are fewer
	 */
	private static function firstTwo(string $content, string $old): array {
		$length = strlen($old);
		if ($length > strlen($content)) {
			return [];
		}
		if ($length <= self::SHORT_BYTES) {
			$first = strpos($content, $old);
			if ($first === false) {
				return [];
			}
			$second = strpos($content, $old, $first + 1);
			return $second === false ? [$first] : [$first, $second];
		}
		return self::knuthMorrisPratt($content, $old, $length);
	}

	/**
	 * @param string $content content to search, at least as long as the snippet
	 * @param string $old snippet, longer than SHORT_BYTES
	 * @param int $length strlen($old)
	 * @return list<int> the offsets of its first two matches, overlapping ones included; fewer when there are fewer
	 */
	private static function knuthMorrisPratt(string $content, string $old, int $length): array {
		$prefix = self::prefixTable($old);
		$matches = [];
		$matched = 0;
		for ($i = 0, $end = strlen($content); $i < $end; $i++) {
			while ($matched > 0 && $content[$i] !== $old[$matched]) {
				$matched = $prefix[$matched - 1];
			}
			if ($content[$i] === $old[$matched]) {
				$matched++;
			}
			if ($matched === $length) {
				$matches[] = $i - $length + 1;
				if (count($matches) === 2) {
					break;
				}
				// The longest proper border of the snippet stays matched, so an overlapping match is found too.
				$matched = $prefix[$length - 1];
			}
		}
		return $matches;
	}

	/**
	 * @param string $old snippet, never empty
	 * @return list<int> for each prefix of the snippet, the length of its longest proper prefix that is also a suffix
	 */
	private static function prefixTable(string $old): array {
		$table = [0];
		$border = 0;
		for ($i = 1, $length = strlen($old); $i < $length; $i++) {
			while ($border > 0 && $old[$i] !== $old[$border]) {
				$border = $table[$border - 1];
			}
			if ($old[$i] === $old[$border]) {
				$border++;
			}
			$table[$i] = $border;
		}
		return $table;
	}
}
