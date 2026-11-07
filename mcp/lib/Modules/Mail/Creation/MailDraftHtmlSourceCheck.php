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
 * Accepts only source syntax that legacy libxml and libxml 2.14+ (HTML5 tokenizer) parse alike, refusing the rest.
 *
 * The check does not emulate either parser. It reads the whole source, including comments and discarded subtrees,
 * as a sequence of well-formed comments, conditional delimiters, plain start and end tags, and text without "<";
 * anything else is refused, because the two parsers recover it differently and often silently.
 */
final class MailDraftHtmlSourceCheck {
	/** Attribute name without characters the parsers read differently. */
	private const NAME = '[a-zA-Z_:][-a-zA-Z0-9_:.]*';
	/** Quoted value, or unquoted value without whitespace, quotes, "=", "<", ">" or "`". */
	private const VALUE = '(?:"[^"]*"|\'[^\']*\'|[^\t\n "\'=<>`]+)';
	/** Attribute: a name, optionally followed by "=" and a value. */
	private const ATTRIBUTE = self::NAME . '(?:[\t\n ]*=[\t\n ]*' . self::VALUE . ')?';
	/** One token at the current "<": comment, conditional delimiter, start tag or end tag; nothing else starts with "<". */
	private const TOKEN = '~\G(?:<!--(?!-?>)(?:(?!--)[\s\S])*-->|<!\[(?:if\b[^\]>]*|endif)\]>'
		. '|<([a-zA-Z][a-zA-Z0-9]*)((?:[\t\n ]+' . self::ATTRIBUTE . ')*)[\t\n ]*/?>|</[a-zA-Z][a-zA-Z0-9]*[\t\n ]*>)~';
	/**
	 * Elements the parsers build differently: raw text until end of input, foreign content, and elements void only
	 * in HTML5, which legacy libxml keeps open around the following content.
	 */
	private const DIVERGENT = ['plaintext', 'xmp', 'listing', 'noembed', 'noframes', 'svg', 'math', 'embed', 'source', 'track',
		'wbr', 'keygen'];
	/** Raw text or escapable raw text in at least one parser: content up to the end tag must not contain "<". */
	private const RAW = ['script', 'style', 'title', 'textarea', 'iframe', 'noscript'];
	/** Controls each parser handles differently in source text: C0 other than tab and line feed, and noncharacters. */
	private const CONTROLS = '/[\x{00}-\x{08}\x{0B}-\x{1F}\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}'
		. '\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}'
		. '\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}'
		. '\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u';

	/** Static check only. @return void */
	private function __construct() {}

	/**
	 * Source tokens: comments, discarded subtrees, conditional delimiters and tags with quoted attribute values.
	 *
	 * Group 1 names a discarded subtree; everything between matches is text. Used to place conditional markers.
	 *
	 * @return string PCRE pattern
	 */
	public static function tokens(): string {
		$blocked = implode('|', MailDraftHtmlAllow::SUBTREES);
		return '~<!--[\s\S]*?-->|<(' . $blocked . ')\b[^>]*>[\s\S]*?</\1\s*>|<!\[(?:if\b[^\]]*|endif)\]>|<[a-zA-Z/][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>~i';
	}

	/**
	 * Normalizes line breaks as HTML5 does, then checks every token and escapes loose ampersands in text and values.
	 *
	 * Comments are kept as written: neither parser reads references in them and the sanitizer removes them.
	 *
	 * @param string $html UTF-8 source fragment
	 * @return string the source to parse
	 * @throws ToolFailure for syntax the parsers may read differently
	 */
	public static function prepare(string $html): string {
		$html = str_replace(["\r\n", "\r"], "\n", $html);
		if (preg_match(self::CONTROLS, $html) !== 0) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		$out = '';
		$offset = 0;
		while (($next = strpos($html, '<', $offset)) !== false) {
			$out .= MailDraftHtmlReferences::text(substr($html, $offset, $next - $offset));
			if (preg_match(self::TOKEN, $html, $token, 0, $next) !== 1) {
				throw new ToolFailure(MailDraftMessages::htmlInvalid());
			}
			$offset = $next + strlen($token[0]);
			if (!isset($token[1]) || $token[1] === '') {
				$out .= $token[0];
				continue;
			}
			$out .= self::startTag($token[0], $token[1], $token[2]);
			if (in_array(strtolower($token[1]), self::RAW, true)) {
				// Each parser ends raw text differently around "<"; plain text up to the end tag reads alike.
				if (preg_match('~\G([^<]*)(</' . $token[1] . '[\t\n ]*>)~i', $html, $raw, 0, $offset) !== 1) {
					throw new ToolFailure(MailDraftMessages::htmlInvalid());
				}
				$out .= MailDraftHtmlReferences::text($raw[1]) . $raw[2];
				$offset += strlen($raw[0]);
			}
		}
		return $out . MailDraftHtmlReferences::text(substr($html, $offset));
	}

	/**
	 * Refuses a divergent element or a repeated attribute name, compared case-insensitively, and checks every value.
	 *
	 * @param string $tag whole start tag
	 * @param string $name element name
	 * @param string $attributes attribute list, each preceded by whitespace
	 * @return string the tag to parse
	 * @throws ToolFailure for a divergent element, a duplicate attribute or an ambiguous reference in a value
	 */
	private static function startTag(string $tag, string $name, string $attributes): string {
		if (in_array(strtolower($name), self::DIVERGENT, true)) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		preg_match_all('~[\t\n ]+(' . self::NAME . ')(?:[\t\n ]*=[\t\n ]*(' . self::VALUE . '))?~', $attributes, $found, PREG_SET_ORDER);
		$seen = [];
		$out = '<' . $name;
		foreach ($found as $attribute) {
			$key = strtolower($attribute[1]);
			if (isset($seen[$key])) {
				throw new ToolFailure(MailDraftMessages::htmlInvalid());
			}
			$seen[$key] = true;
			$value = $attribute[2] ?? '';
			$quote = $value !== '' && ($value[0] === '"' || $value[0] === "'") ? $value[0] : '';
			$inner = $quote === '' ? $value : substr($value, 1, -1);
			$out .= ' ' . $attribute[1] . ($value !== '' ? '=' . $quote . MailDraftHtmlReferences::text($inner) . $quote : '');
		}
		return $out . substr($tag, strlen('<' . $name . $attributes));
	}
}
