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
 * `module.exports` passes. A template literal is a string except for each `${…}` interpolation, which is scanned
 * as code. Inside that interpolation a `}` ends it only in code: a string, a comment, a nested template and a
 * regex literal may contain `}` without ending the interpolation. An escaped `\`` or `\${` stays in the literal.
 */
final class JsModuleSyntax {
	/** Words that would change the fragment from a classic script into a module or a CommonJS load. */
	private const WORDS = ['import', 'export', 'require'];

	/** Identifiers after which `/` starts a regex, not a division. */
	private const REGEX_KEYWORDS = [
		'return', 'throw', 'case', 'delete', 'void', 'typeof', 'yield', 'await', 'else', 'do', 'in', 'of', 'instanceof',
	];

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
	 * @param string $source the fragment as stored on disk
	 * @return string the code with comments and strings replaced by spaces
	 */
	private static function code(string $source): string {
		$offset = 0;
		return self::span($source, $offset, strlen($source), false);
	}

	/**
	 * One code span. Inside a template interpolation the matching code `}` ends it.
	 *
	 * @param string $source the fragment as stored on disk
	 * @param int $offset current index, advanced to the first character after this span
	 * @param int $length byte length of `$source`
	 * @param bool $interpolation true when this span is the inside of `${…}`
	 * @return string the code in the span
	 */
	private static function span(string $source, int &$offset, int $length, bool $interpolation): string {
		$out = '';
		$depth = $interpolation ? 1 : 0;
		$regexOk = true;
		$ident = '';
		while ($offset < $length) {
			$c = $source[$offset];
			if (self::isIdent($c)) {
				$ident .= $c;
				$out .= $c;
				$offset++;
				$regexOk = false;
				continue;
			}
			if ($ident !== '' && in_array($ident, self::REGEX_KEYWORDS, true)) {
				$regexOk = true;
			}
			$ident = '';
			$next = $offset + 1 < $length ? $source[$offset + 1] : '';
			if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
				if ($c === "\n") {
					$regexOk = true;
				}
				$out .= $c;
				$offset++;
				continue;
			}
			if ($c === '/' && $next === '/') {
				$offset += 2;
				while ($offset < $length && $source[$offset] !== "\n") {
					$offset++;
				}
				$out .= ' ';
				continue;
			}
			if ($c === '/' && $next === '*') {
				$offset += 2;
				while ($offset + 1 < $length && !($source[$offset] === '*' && $source[$offset + 1] === '/')) {
					$offset++;
				}
				$offset += 2;
				$out .= ' ';
				continue;
			}
			if ($c === '/' && $regexOk && self::skipRegex($source, $offset, $length)) {
				$out .= ' ';
				$regexOk = false;
				continue;
			}
			if ($c === '"' || $c === "'") {
				self::skipQuoted($source, $offset, $length, $c);
				$out .= ' ';
				$regexOk = false;
				continue;
			}
			if ($c === '`') {
				$out .= self::template($source, $offset, $length);
				$regexOk = false;
				continue;
			}
			if ($interpolation && $c === '{') {
				$depth++;
				$out .= $c;
				$offset++;
				$regexOk = true;
				continue;
			}
			if ($interpolation && $c === '}') {
				$depth--;
				$offset++;
				if ($depth === 0) {
					break;
				}
				$out .= '}';
				$regexOk = true;
				continue;
			}
			$out .= $c;
			$offset++;
			$regexOk = !($c === ')' || $c === ']' || $c === '.');
		}
		return $out;
	}

	/**
	 * A template literal. Literal parts are dropped; each interpolation is scanned as code.
	 *
	 * @param string $source the fragment as stored on disk
	 * @param int $offset index of the opening backtick, advanced past the closing one
	 * @param int $length byte length of `$source`
	 * @return string the code inside the interpolations
	 */
	private static function template(string $source, int &$offset, int $length): string {
		$out = '';
		$offset++;
		while ($offset < $length) {
			if ($source[$offset] === '\\') {
				$offset += $offset + 1 < $length ? 2 : 1;
				continue;
			}
			if ($source[$offset] === '`') {
				$offset++;
				break;
			}
			$next = $offset + 1 < $length ? $source[$offset + 1] : '';
			if ($source[$offset] === '$' && $next === '{') {
				$offset += 2;
				$out .= ' ' . self::span($source, $offset, $length, true) . ' ';
				continue;
			}
			$offset++;
		}
		return $out === '' ? ' ' : $out;
	}

	/**
	 * Consumes one regex literal, including a `}` in the body or in a character class.
	 *
	 * @param string $source the fragment as stored on disk
	 * @param int $offset index of the opening slash; unchanged when this is not a regex
	 * @param int $length byte length of `$source`
	 * @return bool true when a regex was consumed
	 */
	private static function skipRegex(string $source, int &$offset, int $length): bool {
		$start = $offset;
		$offset++;
		$class = false;
		while ($offset < $length) {
			$c = $source[$offset];
			if ($c === '\\') {
				$offset += $offset + 1 < $length ? 2 : 1;
				continue;
			}
			if ($c === "\n") {
				break;
			}
			if ($c === '[' && !$class) {
				$class = true;
				$offset++;
				continue;
			}
			if ($c === ']' && $class) {
				$class = false;
				$offset++;
				continue;
			}
			if ($c === '/' && !$class) {
				$offset++;
				while ($offset < $length && ctype_alpha($source[$offset])) {
					$offset++;
				}
				return true;
			}
			$offset++;
		}
		$offset = $start;
		return false;
	}

	/**
	 * Skips one quoted string, including an escaped quote and a `}` inside it.
	 *
	 * @param string $source the fragment as stored on disk
	 * @param int $offset index of the opening quote, advanced past the closing one
	 * @param int $length byte length of `$source`
	 * @param string $quote `'` or `"`
	 * @return void
	 */
	private static function skipQuoted(string $source, int &$offset, int $length, string $quote): void {
		$offset++;
		while ($offset < $length) {
			if ($source[$offset] === '\\') {
				$offset += $offset + 1 < $length ? 2 : 1;
				continue;
			}
			if ($source[$offset] === $quote) {
				$offset++;
				break;
			}
			$offset++;
		}
	}

	/**
	 * @param string $c one source byte
	 * @return bool true when it continues a JavaScript identifier
	 */
	private static function isIdent(string $c): bool {
		return $c !== '' && (ctype_alnum($c) || str_contains('$_', $c));
	}
}
