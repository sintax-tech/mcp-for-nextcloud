<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Applies the class renames of a move to the text of a non-PHP test file (a golden that lists DI keys, an effect
 * contract that records the class of a refusal), so such a file may follow the move and nothing else, and finds the
 * non-PHP files that still name a class the move retired.
 *
 * Only whole names change: the exact old name, written plainly (`Acme\A\Probe`) or JSON-escaped (`Acme\\A\\Probe`),
 * optionally after a leading separator, never preceded by a name character and never followed by one or by a deeper
 * namespace segment. Any other edit of the file still makes it differ from the rewritten text.
 */
final class ClassRewrites {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * Rewrites every whole old class name of `$renames` in `$text`.
	 *
	 * @param string $text non-PHP text
	 * @param array<string,string> $renames old class => new class
	 * @return string the text with the renamed classes
	 */
	public static function text(string $text, array $renames): string {
		uksort($renames, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
		foreach ($renames as $old => $new) {
			foreach (['\\', '\\\\'] as $separator) {
				$replacement = str_replace('\\', $separator, $new);
				$text = (string)preg_replace_callback(self::pattern($old, $separator), static fn (array $m): string => ($m[1] ?? '') . $replacement, $text);
			}
		}
		return $text;
	}

	/**
	 * Reports every whole old name of a class retired in the range that a non-PHP file of the new tree still holds,
	 * whether the file changed or not (a golden forgotten entirely), with the same boundaries as {@see text()}.
	 * Exact registered certificate paths preserve sealed historical names; their own gate verifies their seals.
	 *
	 * @param array<string,string> $files new tree, path => content; PHP files and the allowed data are skipped
	 * @param array<string,string> $retired old class => new class, for classes of the app no longer declared
	 * @return list<string> diagnostics with file and line
	 */
	public static function stale(array $files, array $retired): array {
		$problems = [];
		$certificates = array_column(FrozenPathCertificates::all(), 'path');
		foreach ($files as $path => $content) {
			if (str_ends_with($path, '.php') || in_array($path, MoveEquivalence::ALLOWED_DATA, true) || in_array($path, $certificates, true)) {
				continue;
			}
			foreach ($retired as $old => $new) {
				foreach (['\\', '\\\\'] as $separator) {
					preg_match_all(self::pattern($old, $separator), $content, $matches, PREG_OFFSET_CAPTURE);
					foreach ($matches[0] as [, $offset]) {
						$line = substr_count(substr($content, 0, $offset), "\n") + 1;
						$problems[] = "$path:$line: still refers to $old, moved to $new in this range";
					}
				}
			}
		}
		return $problems;
	}

	/**
	 * The pattern of one whole old name: optional leading separator (captured), never after a name character, never
	 * followed by one or by a deeper namespace segment.
	 *
	 * @param string $old old class name with single backslashes
	 * @param string $separator one backslash for plain text, two for JSON-escaped text
	 * @return string PCRE pattern
	 */
	private static function pattern(string $old, string $separator): string {
		$name = str_replace('\\', $separator, $old);
		return '~(?<![\w\\\\])(' . preg_quote($separator, '~') . ')?' . preg_quote($name, '~') . '(?!\w)(?!' . preg_quote($separator, '~') . '\w)~';
	}
}
