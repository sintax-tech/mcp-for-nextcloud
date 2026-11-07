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
 * Refuses or escapes source constructs that libxml parsers recover differently, without relying on their diagnostics.
 *
 * Legacy libxml reports duplicate attributes and character references without a semicolon; libxml 2.14 and later
 * (HTML5 tokenizer) recover both silently, so the check reads the source itself and gives the same answer on both.
 * Loose ampersands, which legacy libxml refused, are escaped as text.
 */
final class MailDraftHtmlSourceCheck {
	/** Static check only. @return void */
	private function __construct() {}

	/**
	 * Source tokens: comments, discarded subtrees, conditional delimiters and tags with quoted attribute values.
	 *
	 * Group 1 names a discarded subtree; everything between matches is text.
	 *
	 * @return string PCRE pattern
	 */
	public static function tokens(): string {
		$blocked = implode('|', MailDraftHtmlAllow::SUBTREES);
		return '~<!--[\s\S]*?-->|<(' . $blocked . ')\b[^>]*>[\s\S]*?</\1\s*>|<!\[(?:if\b[^\]]*|endif)\]>|<[a-zA-Z/][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>~i';
	}

	/**
	 * Checks text and attribute values outside comments and discarded subtrees, whose content is never saved.
	 *
	 * @param string $html UTF-8 source fragment
	 * @return string the source to parse, with loose ampersands escaped
	 * @throws ToolFailure for a duplicate attribute or an ambiguous character reference
	 */
	public static function prepare(string $html): string {
		if (preg_match_all(self::tokens(), $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		$out = '';
		$offset = 0;
		foreach ($matches as $match) {
			[$token, $start] = $match[0];
			$out .= self::references(substr($html, $offset, $start - $offset));
			$kept = str_starts_with($token, '<!') || ($match[1][0] ?? '') !== '';
			$out .= $kept ? $token : self::tag($token);
			$offset = $start + strlen($token);
		}
		return $out . self::references(substr($html, $offset));
	}

	/**
	 * Refuses a repeated attribute name, compared case-insensitively as both parsers do, and checks every value.
	 *
	 * @param string $tag start or end tag token
	 * @return string the tag to parse
	 * @throws ToolFailure for a duplicate attribute or an ambiguous character reference in a value
	 */
	private static function tag(string $tag): string {
		if (preg_match('~^<[a-zA-Z][^\s/>]*~', $tag, $name) !== 1) {
			return $tag;
		}
		$seen = [];
		$attributes = preg_replace_callback('~([^\s"\'>/=]+)(?:(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'>]*))?~',
			static function (array $attribute) use (&$seen): string {
				$key = strtolower($attribute[1]);
				if (isset($seen[$key])) {
					throw new ToolFailure(MailDraftMessages::htmlInvalid());
				}
				$seen[$key] = true;
				$value = $attribute[3] ?? '';
				$quote = $value !== '' && ($value[0] === '"' || $value[0] === "'") ? $value[0] : '';
				$inner = $quote === '' ? $value : substr($value, 1, -1);
				return $attribute[1] . ($attribute[2] ?? '') . $quote . self::references($inner) . $quote;
			}, substr($tag, strlen($name[0])));
		if ($attributes === null) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		return $name[0] . $attributes;
	}

	/**
	 * Checks every character reference of a text run or attribute value and escapes loose ampersands.
	 *
	 * @param string $text text or attribute value as written
	 * @return string the text to parse, with the same meaning on every parser
	 * @throws ToolFailure for an ambiguous character reference
	 */
	private static function references(string $text): string {
		return preg_replace_callback('/&(#[xX][0-9a-fA-F]*;?|#[0-9]*;?|[a-zA-Z][a-zA-Z0-9]*;?)?/',
			static fn (array $reference): string => self::reference($reference[1] ?? ''), $text)
			?? throw new ToolFailure(MailDraftMessages::htmlInvalid());
	}

	/**
	 * Escapes a loose ampersand as text; a numeric reference needs its semicolon and a code point both parsers decode alike.
	 *
	 * An ampersand that starts no reference ("A & B", "AT&T", "?a=1&b=2") becomes &amp;, so the shown text and the
	 * decoded attribute value stay exactly as written. A named reference is kept only with its semicolon and a name
	 * both parsers know (HTML 4.01 and &apos;); any other name, and a name without semicolon such as "&copy 2026" or
	 * "&amp", is kept as literal text, because legacy libxml leaves unknown names literal while HTML5 decodes its own
	 * names and legacy prefixes. NUL, C1 controls (which HTML5 remaps to windows-1252), surrogates and values beyond
	 * Unicode are refused.
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
		if (preg_match('/^#(?:[xX]0*([0-9a-fA-F]{1,6})|0*([0-9]{1,7}));$/D', $reference, $digits) !== 1) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		$code = $digits[1] !== '' ? (int)hexdec($digits[1]) : (int)$digits[2];
		if ($code === 0 || ($code >= 0x80 && $code <= 0x9F) || ($code >= 0xD800 && $code <= 0xDFFF) || $code > 0x10FFFF) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		return '&' . $reference;
	}
}
