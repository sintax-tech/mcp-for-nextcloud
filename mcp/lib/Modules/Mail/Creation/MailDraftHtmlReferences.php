<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;

/**
 * Character references of draft text and attribute values with one meaning on legacy and HTML5 libxml parsers.
 *
 * An ampersand that starts no reference ("A & B", "AT&T", "?a=1&b=2") becomes &amp;, so the shown text and the
 * decoded attribute value stay exactly as written. A named reference is kept only with its semicolon and a name
 * both parsers know (HTML 4.01 and &apos;); any other name, and a name without semicolon such as "&copy 2026" or
 * "&amp", is kept as literal text, because legacy libxml leaves unknown names literal while HTML5 decodes its own
 * names and legacy prefixes. A numeric reference needs its semicolon and a code point both parsers keep alike:
 * tab, line feed and visible characters; other controls, carriage return, DEL, C1 controls (remapped by HTML5),
 * surrogates, noncharacters and values beyond Unicode are refused.
 */
final class MailDraftHtmlReferences {
	/** Static rules only. @return void */
	private function __construct() {}

	/**
	 * Escapes loose ampersands and checks every character reference of a text run or attribute value.
	 *
	 * @param string $text text or attribute value as written, without "<"
	 * @return string the text to parse, with the same meaning on every parser
	 * @throws ToolFailure for an ambiguous numeric reference
	 */
	public static function text(string $text): string {
		return preg_replace_callback('/&(#[xX][0-9a-fA-F]*;?|#[0-9]*;?|[a-zA-Z][a-zA-Z0-9]*;?)?/',
			static fn (array $reference): string => self::reference($reference[1] ?? ''), $text)
			?? throw new ToolFailure(MailDraftMessages::htmlInvalid());
	}

	/**
	 * Keeps an unambiguous reference, escapes a loose or unknown one as text and refuses an ambiguous numeric one.
	 *
	 * @param string $reference text after the ampersand that may continue a reference
	 * @return string the reference to parse
	 * @throws ToolFailure for an ambiguous numeric reference
	 */
	private static function reference(string $reference): string {
		if (!str_starts_with($reference, '#')) {
			$named = '&' . $reference;
			return str_ends_with($reference, ';') && html_entity_decode($named, ENT_QUOTES | ENT_XHTML, 'UTF-8') !== $named
				? $named : '&amp;' . $reference;
		}
		if (preg_match('/^#(?:[xX]0*([0-9a-fA-F]{1,6})|0*([0-9]{1,7}));$/D', $reference, $digits) !== 1
			|| !self::kept($digits[1] !== '' ? (int)hexdec($digits[1]) : (int)$digits[2])) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		return '&' . $reference;
	}

	/**
	 * Code points both parsers decode to the same character.
	 *
	 * @param int $code decoded code point
	 * @return bool true for tab, line feed and visible characters outside surrogates and noncharacters
	 */
	private static function kept(int $code): bool {
		return $code === 0x09 || $code === 0x0A || ($code >= 0x20 && $code <= 0x7E) || ($code >= 0xA0 && $code <= 0x10FFFF
			&& !($code >= 0xD800 && $code <= 0xDFFF) && !($code >= 0xFDD0 && $code <= 0xFDEF) && ($code & 0xFFFE) !== 0xFFFE);
	}
}
