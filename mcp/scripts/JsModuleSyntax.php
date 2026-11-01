<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

/**
 * Refuses JavaScript module syntax in a bundle fragment.
 *
 * Fragments are concatenated as classic scripts. `import`, `export` and `require` are refused when they appear as
 * whole words in code. The same words inside a comment or a string are kept, and `exports` is not `export`, so
 * `module.exports` (used by the existing admin scripts so Node tests can load them) passes.
 */
final class JsModuleSyntax {
	/** Words that would change the fragment from a classic script into a module or a CommonJS load. */
	private const WORDS = ['import', 'export', 'require'];

	/**
	 * The first forbidden word in the source, if any.
	 *
	 * @param string $source the fragment as stored on disk
	 * @return string|null `import`, `export` or `require`, or null when the fragment is a classic script
	 */
	public static function violation(string $source): ?string {
		$code = self::code($source);
		foreach (self::WORDS as $word) {
			if (preg_match('/\b' . $word . '\b/', $code) === 1) {
				return $word;
			}
		}
		return null;
	}

	/**
	 * The source with comments and quoted text removed, so a word inside them is not code.
	 *
	 * Template literals are treated as one string up to the closing backtick. A forbidden word only inside
	 * `${…}` of a template is therefore not seen; a real `import` / `export` / `require` statement is.
	 *
	 * @param string $source the fragment as stored on disk
	 * @return string the code with comments and strings replaced by spaces
	 */
	private static function code(string $source): string {
		$length = strlen($source);
		$out = '';
		$i = 0;
		while ($i < $length) {
			$c = $source[$i];
			$next = $i + 1 < $length ? $source[$i + 1] : '';
			if ($c === '/' && $next === '/') {
				$i += 2;
				while ($i < $length && $source[$i] !== "\n") {
					$i++;
				}
				$out .= ' ';
				continue;
			}
			if ($c === '/' && $next === '*') {
				$i += 2;
				while ($i + 1 < $length && !($source[$i] === '*' && $source[$i + 1] === '/')) {
					$i++;
				}
				$i += 2;
				$out .= ' ';
				continue;
			}
			if ($c === '"' || $c === "'" || $c === '`') {
				$quote = $c;
				$i++;
				while ($i < $length) {
					if ($source[$i] === '\\') {
						$i += 2;
						continue;
					}
					if ($source[$i] === $quote) {
						$i++;
						break;
					}
					$i++;
				}
				$out .= ' ';
				continue;
			}
			$out .= $c;
			$i++;
		}
		return $out;
	}
}
