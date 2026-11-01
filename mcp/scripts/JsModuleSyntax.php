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
 * Fragments are concatenated as classic scripts. After comments are blanked, `import`, `export` and `require`
 * are refused as whole identifiers anywhere else in the file: code, strings and template literals. A comment is
 * removed only outside a string or a template, so `//` inside either stays text and the word there still fails.
 * `}` is not used to decide what is code. `exports` is not `export`, so `module.exports` passes, and so does a
 * longer identifier such as `importantValue` or `exported`.
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
		$blank = self::blankComments($source);
		$found = preg_match(
			'/(?<![A-Za-z0-9_$])(' . self::WORDS . ')(?![A-Za-z0-9_$])/',
			$blank,
			$match,
			PREG_OFFSET_CAPTURE,
		);
		if ($found !== 1) {
			return null;
		}
		$offset = $match[1][1];
		return [$match[1][0], 1 + substr_count(substr($source, 0, $offset), "\n")];
	}

	/**
	 * The source with comments replaced by spaces. Newlines stay, so a later offset is the same line.
	 *
	 * A string or a template is copied unchanged, including a `//` or a `/*` inside it. `${…}` is code again,
	 * and a nested template inside it is a string again. The brace that closes the interpolation is counted
	 * only in that code, not inside the string or the nested template.
	 *
	 * @param string $source the fragment as stored on disk
	 * @return string the same bytes, with comment text turned into spaces
	 */
	private static function blankComments(string $source): string {
		$length = strlen($source);
		$out = '';
		$i = 0;
		/** @var list<array{kind: string, braces: int, root: bool}> $stack code or template frames */
		$stack = [['kind' => 'code', 'braces' => 0, 'root' => true]];
		while ($i < $length) {
			$top = count($stack) - 1;
			$c = $source[$i];
			$next = $i + 1 < $length ? $source[$i + 1] : '';
			if ($stack[$top]['kind'] === 'template') {
				if ($c === '\\' && $next !== '') {
					$out .= $c . $next;
					$i += 2;
					continue;
				}
				if ($c === '`') {
					array_pop($stack);
					$out .= $c;
					$i++;
					continue;
				}
				if ($c === '$' && $next === '{') {
					$stack[] = ['kind' => 'code', 'braces' => 0, 'root' => false];
					$out .= $c . $next;
					$i += 2;
					continue;
				}
				$out .= $c;
				$i++;
				continue;
			}
			if ($c === '/' && $next === '/') {
				while ($i < $length && $source[$i] !== "\n") {
					$out .= ' ';
					$i++;
				}
				continue;
			}
			if ($c === '/' && $next === '*') {
				$out .= '  ';
				$i += 2;
				while ($i < $length && !($source[$i] === '*' && ($i + 1 < $length && $source[$i + 1] === '/'))) {
					$out .= $source[$i] === "\n" ? "\n" : ' ';
					$i++;
				}
				if ($i < $length) {
					$out .= ' ';
					$i++;
					if ($i < $length) {
						$out .= ' ';
						$i++;
					}
				}
				continue;
			}
			if ($c === '"' || $c === "'") {
				self::takeQuoted($source, $i, $length, $out, $c);
				continue;
			}
			if ($c === '`') {
				$stack[] = ['kind' => 'template', 'braces' => 0, 'root' => false];
				$out .= $c;
				$i++;
				continue;
			}
			if ($c === '{') {
				$stack[$top]['braces']++;
			} elseif ($c === '}' && $stack[$top]['braces'] > 0) {
				$stack[$top]['braces']--;
			} elseif ($c === '}' && $stack[$top]['root'] === false) {
				array_pop($stack);
			}
			$out .= $c;
			$i++;
		}
		return $out;
	}

	/**
	 * Copies one quoted string, escapes included, so a comment mark inside it is kept.
	 *
	 * @param string $source the fragment as stored on disk
	 * @param int $i index of the opening quote, advanced past the closing one
	 * @param int $length byte length of `$source`
	 * @param string $out the blanked source being built
	 * @param string $quote `'` or `"`
	 * @return void
	 */
	private static function takeQuoted(string $source, int &$i, int $length, string &$out, string $quote): void {
		$out .= $quote;
		$i++;
		while ($i < $length) {
			$c = $source[$i];
			if ($c === '\\' && $i + 1 < $length) {
				$out .= $c . $source[$i + 1];
				$i += 2;
				continue;
			}
			$out .= $c;
			$i++;
			if ($c === $quote) {
				break;
			}
		}
	}
}
