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
 * as a sequence of well-formed comments, conditional delimiters, plain start and end tags of known elements, and
 * text without "<", in a wrapper order both parsers keep (MailDraftHtmlStructure); anything else is refused,
 * because the two parsers recover it differently and often silently.
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
		. '|<([a-zA-Z][a-zA-Z0-9]*)((?:[\t\n ]+' . self::ATTRIBUTE . ')*)[\t\n ]*(/?)>|</([a-zA-Z][a-zA-Z0-9]*)[\t\n ]*>)~';
	/**
	 * Elements outside the saved vocabulary that both parsers build alike: the document wrappers and head elements,
	 * the subtrees the filter removes, and wrappers the filter unwraps. Any other element is refused,
	 * so obsolete, HTML5-only, foreign and unknown elements never reach parsers that build them differently.
	 */
	private const KNOWN = ['html', 'head', 'body', 'meta', 'title', 'base', 'link', 'script', 'style', 'iframe', 'object', 'form',
		'template', 'noscript', 'textarea', 'img', 'font', 'center', ...self::UNWRAPPED];
	/**
	 * Elements outside the saved vocabulary that 1.3.0 unwrapped, keeping their content, and that both parsers build
	 * alike with the identity and the real engine (security review of 1.3.1); "/>" stays refused on them.
	 */
	private const UNWRAPPED = ['h4', 'h5', 'h6', 'sup', 'sub', 'small', 'mark', 'abbr', 'cite', 'q', 'ins', 'kbd', 'dl', 'dt', 'dd',
		'section', 'article', 'header', 'footer', 'figure', 'figcaption', 'acronym', 'big', 'strike', 'tt', 'samp', 'var',
		'address', 'dfn'];
	/** Known elements without content or end tag; only these may end with "/>". */
	private const VOID = ['br', 'hr', 'img', 'meta', 'link', 'base'];
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
		$structure = new MailDraftHtmlStructure();
		$out = '';
		$offset = 0;
		while (($next = strpos($html, '<', $offset)) !== false) {
			$out .= self::text($structure, substr($html, $offset, $next - $offset));
			if (preg_match(self::TOKEN, $html, $token, 0, $next) !== 1) {
				throw new ToolFailure(MailDraftMessages::htmlInvalid());
			}
			$offset = $next + strlen($token[0]);
			$name = strtolower(($token[1] ?? '') . ($token[4] ?? ''));
			if ($name === '') {
				$structure->comment(str_starts_with($token[0], '<!['));
				$out .= $token[0];
				continue;
			}
			self::known($name, ($token[4] ?? '') !== '', ($token[3] ?? '') !== '');
			if (($token[4] ?? '') !== '') {
				$structure->end($name);
				$out .= $token[0];
				continue;
			}
			$structure->start($name, in_array($name, self::VOID, true));
			$out .= self::startTag($token[0], $token[1], $token[2]);
			if (in_array($name, self::RAW, true)) {
				// Each parser ends raw text differently around "<"; plain text up to the end tag reads alike.
				if (preg_match('~\G([^<]*)(</' . $token[1] . '[\t\n ]*>)~i', $html, $raw, 0, $offset) !== 1) {
					throw new ToolFailure(MailDraftMessages::htmlInvalid());
				}
				$structure->end($name);
				$out .= MailDraftHtmlReferences::text($raw[1]) . $raw[2];
				$offset += strlen($raw[0]);
			}
		}
		$out .= self::text($structure, substr($html, $offset));
		$structure->finish();
		return $out;
	}

	/**
	 * Escapes and records text between tokens, dropping whitespace that shows nothing.
	 *
	 * @param MailDraftHtmlStructure $structure wrapper order of this source
	 * @param string $text text without "<"
	 * @return string the text to parse
	 * @throws ToolFailure for misplaced text or an ambiguous reference
	 */
	private static function text(MailDraftHtmlStructure $structure, string $text): string {
		$text = $structure->visible($text);
		$structure->text($text);
		return MailDraftHtmlReferences::text($text);
	}

	/**
	 * Accepts a known element, "/>" only on a void element, and an end tag only on an element with content.
	 *
	 * @param string $name lowercase element name
	 * @param bool $end whether the token is an end tag
	 * @param bool $selfClosing whether the start tag ends with "/>"
	 * @return void
	 * @throws ToolFailure for an unknown element or a misplaced "/>" or end tag
	 */
	private static function known(string $name, bool $end, bool $selfClosing): void {
		$void = in_array($name, self::VOID, true);
		if ((!in_array($name, MailDraftHtmlAllow::ELEMENTS, true) && !in_array($name, self::KNOWN, true))
			|| ($end && $void) || ($selfClosing && !$void)) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
	}

	/**
	 * Refuses a repeated attribute name, compared case-insensitively, and a value that holds "<" or ">" once decoded,
	 * which the parsers serialize differently; checks every reference.
	 *
	 * @param string $tag whole start tag
	 * @param string $name element name
	 * @param string $attributes attribute list, each preceded by whitespace
	 * @return string the tag to parse
	 * @throws ToolFailure for a duplicate attribute, an angle bracket or an ambiguous reference in a value
	 */
	private static function startTag(string $tag, string $name, string $attributes): string {
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
			$inner = MailDraftHtmlReferences::text($quote === '' ? $value : substr($value, 1, -1));
			if (strpbrk(html_entity_decode($inner, ENT_QUOTES | ENT_XHTML, 'UTF-8'), '<>') !== false) {
				throw new ToolFailure(MailDraftMessages::htmlInvalid());
			}
			$out .= ' ' . $attribute[1] . ($value !== '' ? '=' . $quote . $inner . $quote : '');
		}
		return $out . substr($tag, strlen('<' . $name . $attributes));
	}
}
