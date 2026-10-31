<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Brings a document XML entry (DOCX `word/document.xml`, ODT `content.xml`) to UTF-8 from the charset it declares,
 * before its tags are removed and its entities decoded, for files_read and mail attachments alike.
 *
 * The charset comes from the document itself, never from a guess: a UTF-16 byte order mark, or else the `encoding`
 * of the XML declaration. Entities are left as written, so `&#x20AC;` in a Windows-1252 entry decodes later into the
 * same UTF-8 as the converted letters around it. Without a declaration, or with UTF-8 or a charset mbstring does not
 * know, the bytes are returned as they are and {@see ExtractedText} replaces whatever is invalid.
 */
final class XmlCharset {
	/** The XML declaration and its encoding pseudo-attribute, at the very start of the entry. */
	private const DECLARATION = '/^(<\?xml\b[^>]*?\bencoding\s*=\s*)(["\'])([A-Za-z][A-Za-z0-9._-]*)\2/';

	/** Byte order marks of UTF-16, with the charset they announce. */
	private const UTF16_MARKS = ["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The entry in UTF-8, its declaration saying so.
	 *
	 * @param string $xml the entry as stored in the archive
	 * @return string the entry converted from its declared charset, or unchanged when it declares none it can be read from
	 */
	public static function toUtf8(string $xml): string {
		foreach (self::UTF16_MARKS as $mark => $charset) {
			if (str_starts_with($xml, $mark)) {
				return self::converted(substr($xml, 2), $charset) ?? $xml;
			}
		}
		if (preg_match(self::DECLARATION, $xml, $match) !== 1 || in_array(strtolower($match[3]), ['utf-8', 'utf8', 'us-ascii'], true)) {
			return $xml;
		}
		$converted = self::converted($xml, $match[3]);
		if ($converted === null) {
			return $xml;
		}
		return preg_replace(self::DECLARATION, '${1}${2}UTF-8${2}', $converted, 1) ?? $converted;
	}

	/**
	 * The bytes converted to UTF-8.
	 *
	 * @param string $bytes the entry
	 * @param string $charset its charset
	 * @return string|null the UTF-8 text, or null when mbstring does not know the charset
	 */
	private static function converted(string $bytes, string $charset): ?string {
		try {
			$converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
		} catch (\ValueError) {
			return null;
		}
		return is_string($converted) ? $converted : null;
	}
}
