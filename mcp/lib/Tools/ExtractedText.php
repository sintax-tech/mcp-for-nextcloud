<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Turns the text a document parser extracted (PDF, DOCX, ODT, of a file or of a mail attachment) into valid UTF-8 that
 * the MCP response can always encode.
 *
 * Parsers do not promise UTF-8. smalot/pdfparser writes each half of a UTF-16 surrogate pair of a ToUnicode map as its
 * own three bytes (CESU-8), so an emoji or any character beyond U+FFFF comes out as bytes json_encode refuses; an XML
 * entry declared in a legacy charset comes out as single bytes. In order:
 * 1. text that is already valid UTF-8 keeps every character;
 * 2. surrogate pairs written as CESU-8 become the character they encode, and lone surrogates become U+FFFD;
 * 3. text with no multibyte UTF-8 sequence at all, no NUL byte and few high bytes is single-byte text and is read as
 *    Windows-1252 (a superset of ISO-8859-1 for every printable character), so accents survive;
 * 4. any other invalid byte becomes U+FFFD: a byte inside UTF-8 text is a broken sequence far more often than a Latin-1
 *    character, and binary content is never guessed into letters.
 * Control characters other than tab, line feed, form feed and carriage return are then removed, C1 controls included.
 */
final class ExtractedText {
	/** One valid UTF-8 sequence (RFC 3629): ASCII, or a well-formed multibyte character without surrogates. */
	private const VALID = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
		. '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';

	/** A UTF-16 surrogate pair written as two CESU-8 sequences: high surrogate, then low surrogate. */
	private const CESU_PAIR = '/\xED([\xA0-\xAF])([\x80-\xBF])\xED([\xB0-\xBF])([\x80-\xBF])/';

	/** A surrogate written as CESU-8 without its other half. */
	private const CESU_HALF = '/\xED[\xA0-\xBF][\x80-\xBF]/';

	/** Control characters removed: C0 except tab, line feed, form feed and carriage return; DEL; C1. */
	private const CONTROLS = '/[\x{0}-\x{8}\x{B}\x{E}-\x{1F}\x{7F}-\x{9F}]+/u';

	/** Largest share of bytes at or above 0x80 that single-byte text may hold before it is taken for binary. */
	private const MAX_HIGH_SHARE = 0.3;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The extracted text as valid UTF-8 without control characters other than tab and line breaks.
	 *
	 * @param string $text what the parser returned, in any encoding
	 * @return string valid UTF-8
	 */
	public static function utf8(string $text): string {
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = self::repaired($text);
		}
		return preg_replace(self::CONTROLS, '', $text) ?? $text;
	}

	/**
	 * Invalid text made valid, by the steps 2 to 4 of the class description.
	 *
	 * @param string $text text that is not valid UTF-8
	 * @return string valid UTF-8
	 */
	private static function repaired(string $text): string {
		$text = preg_replace_callback(self::CESU_PAIR, self::pair(...), $text) ?? $text;
		$text = preg_replace(self::CESU_HALF, "\u{FFFD}", $text) ?? $text;
		if (mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}
		if (self::singleByte($text)) {
			return (string)mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
		}
		return self::substituted($text);
	}

	/**
	 * The character a CESU-8 surrogate pair encodes, as UTF-8.
	 *
	 * @param array<int, string> $match the pair, with the second and third bytes of each half captured
	 * @return string the four-byte UTF-8 sequence
	 */
	private static function pair(array $match): string {
		$high = 0xD000 | ((ord($match[1]) & 0x3F) << 6) | (ord($match[2]) & 0x3F);
		$low = 0xD000 | ((ord($match[3]) & 0x3F) << 6) | (ord($match[4]) & 0x3F);
		return (string)mb_chr(0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
	}

	/**
	 * Whether the text reads as single-byte text: no multibyte UTF-8 sequence, no NUL byte, few high bytes.
	 *
	 * @param string $text invalid UTF-8
	 * @return bool true when it is converted from Windows-1252
	 */
	private static function singleByte(string $text): bool {
		if (str_contains($text, "\0") || preg_match('/(?![\x00-\x7F])(?:' . self::VALID . ')/', $text) === 1) {
			return false;
		}
		$high = strlen($text) - strlen((string)preg_replace('/[\x80-\xFF]+/', '', $text));
		return $high <= strlen($text) * self::MAX_HIGH_SHARE;
	}

	/**
	 * Every byte outside a valid sequence replaced by U+FFFD, the valid sequences kept.
	 *
	 * @param string $text invalid UTF-8
	 * @return string valid UTF-8
	 */
	private static function substituted(string $text): string {
		$result = preg_replace('/(?:' . self::VALID . ')++(*SKIP)(*FAIL)|./s', "\u{FFFD}", $text);
		// Should the pattern engine give up on a huge input, mbstring still returns valid UTF-8 ("?" for each byte).
		return $result ?? mb_scrub($text, 'UTF-8');
	}
}
