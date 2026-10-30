<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Value of a constant PHP string literal (`T_CONSTANT_ENCAPSED_STRING`), decoded by PHP's own grammar.
 *
 * Single quotes only unescape `\\` and `\'`. Double quotes unescape `\n \t \v \e \f \r \\ \$ \"`, octal
 * `\0`–`\777`, hex `\xH`/`\xHH` and `\u{…}`; any other backslash stays in the value, as in PHP (`"\q"` is two
 * bytes). An optional `b`/`B` prefix is ignored.
 */
final class PhpStringLiteral {
	/** Simple escapes of double-quoted strings. */
	private const SIMPLE = ['n' => "\n", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", 'r' => "\r", '\\' => '\\', '$' => '$', '"' => '"'];

	/**
	 * Decodes a literal.
	 *
	 * @param string $literal the token text, quotes included
	 * @return string the bytes PHP builds from it
	 */
	public static function value(string $literal): string {
		$literal = ltrim($literal, 'bB');
		$quote = $literal[0];
		$body = substr($literal, 1, -1);
		if ($quote === "'") {
			return (string)preg_replace_callback("/\\\\([\\\\'])/", static fn (array $m): string => $m[1], $body);
		}
		$pattern = '/\\\\(?:([ntvefr\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/';
		return (string)preg_replace_callback($pattern, static function (array $m): string {
			if (($m[1] ?? '') !== '') {
				return self::SIMPLE[$m[1]];
			}
			if (($m[2] ?? '') !== '') {
				return chr(octdec($m[2]) & 0xFF);
			}
			if (($m[3] ?? '') !== '') {
				return chr(hexdec($m[3]));
			}
			return mb_chr((int)hexdec($m[4]), 'UTF-8') ?: $m[0];
		}, $body);
	}
}
