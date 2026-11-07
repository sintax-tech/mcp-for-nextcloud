<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Text;

/**
 * Brings a document XML entry (DOCX `word/document.xml`, ODT `content.xml`) to UTF-8 from the charset it declares,
 * before its tags are removed and its entities decoded, for files_read and mail attachments alike.
 *
 * The charset comes from the document itself, never from a guess: a UTF-16 byte order mark, or else the `encoding`
 * of the XML declaration, the processing instruction whose target is exactly `xml` at the very start of the entry
 * (`<?xml-stylesheet` and any other instruction declare nothing). Only the text charsets of {@see self::CHARSETS} are
 * converted: mbstring also accepts transfer encodings (BASE64, UUENCODE, Quoted-Printable, HTML-ENTITIES...), which
 * would wipe, scramble or decode the entry early. Entities are left as written, so `&#x20AC;` in a Windows-1252 entry
 * decodes later into the same UTF-8 as the converted letters around it. The declaration of a converted entry names
 * UTF-8, so converting it again changes nothing. Without a declaration, with UTF-8 or with any other charset, the
 * bytes are returned as they are and {@see ExtractedText} replaces whatever is invalid.
 */
final class XmlCharset {
	/** The XML declaration and its encoding pseudo-attribute, at the very start of the entry (XML 1.0, rules 23 and 80). */
	private const DECLARATION = '/^(<\?xml[\x20\x09\x0D\x0A][^>]*?[\x20\x09\x0D\x0A]encoding[\x20\x09\x0D\x0A]*=[\x20\x09\x0D\x0A]*)(["\'])([A-Za-z][A-Za-z0-9._-]*)\2/';

	/** Byte order marks of UTF-16, with the charset they announce. */
	private const UTF16_MARKS = ["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'];

	/**
	 * Text charsets a declaration may name, by their name in lower case without "-", "_" or ".", with the mbstring
	 * name they are read as. UTF-16 and UTF-32 are absent: a declaration readable as ASCII cannot be in them.
	 */
	private const CHARSETS = [
		'windows1251' => 'Windows-1251', 'cp1251' => 'Windows-1251', 'windows1252' => 'Windows-1252', 'cp1252' => 'Windows-1252',
		'windows1254' => 'Windows-1254', 'cp1254' => 'Windows-1254',
		'iso88591' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1', 'l1' => 'ISO-8859-1', 'iso88592' => 'ISO-8859-2', 'latin2' => 'ISO-8859-2',
		'iso88593' => 'ISO-8859-3', 'iso88594' => 'ISO-8859-4', 'iso88595' => 'ISO-8859-5', 'iso88596' => 'ISO-8859-6',
		'iso88597' => 'ISO-8859-7', 'iso88598' => 'ISO-8859-8', 'iso88599' => 'ISO-8859-9', 'latin5' => 'ISO-8859-9',
		'iso885910' => 'ISO-8859-10', 'iso885913' => 'ISO-8859-13', 'iso885914' => 'ISO-8859-14', 'iso885915' => 'ISO-8859-15',
		'latin9' => 'ISO-8859-15', 'iso885916' => 'ISO-8859-16',
		'koi8r' => 'KOI8-R', 'koi8u' => 'KOI8-U', 'cp866' => 'CP866', 'ibm866' => 'CP866', 'cp850' => 'CP850', 'ibm850' => 'CP850',
		'shiftjis' => 'SJIS', 'sjis' => 'SJIS', 'windows31j' => 'CP932', 'cp932' => 'CP932', 'eucjp' => 'EUC-JP', 'iso2022jp' => 'ISO-2022-JP',
		'gb18030' => 'GB18030', 'gbk' => 'CP936', 'cp936' => 'CP936', 'gb2312' => 'EUC-CN', 'euccn' => 'EUC-CN',
		'big5' => 'BIG-5', 'cp950' => 'CP950', 'euckr' => 'EUC-KR', 'ksc56011987' => 'EUC-KR', 'uhc' => 'UHC', 'cp949' => 'UHC',
	];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The entry in UTF-8, its declaration (when it has one) saying so.
	 *
	 * @param string $xml the entry as stored in the archive
	 * @return string the entry converted from its declared charset, or unchanged when it declares none it can be read from
	 */
	public static function toUtf8(string $xml): string {
		foreach (self::UTF16_MARKS as $mark => $charset) {
			if (str_starts_with($xml, $mark)) {
				$converted = self::converted(substr($xml, 2), $charset);
				return $converted === null ? $xml : self::declaringUtf8($converted);
			}
		}
		if (preg_match(self::DECLARATION, $xml, $match) !== 1) {
			return $xml;
		}
		$charset = self::CHARSETS[(string)preg_replace('/[-_.]/', '', strtolower($match[3]))] ?? null;
		$converted = $charset === null ? null : self::converted($xml, $charset);
		return $converted === null ? $xml : self::declaringUtf8($converted);
	}

	/**
	 * The converted entry with the encoding of its declaration, if it has one, changed to UTF-8.
	 *
	 * @param string $xml the entry, already in UTF-8
	 * @return string the entry whose declaration names UTF-8
	 */
	private static function declaringUtf8(string $xml): string {
		return preg_replace(self::DECLARATION, '${1}${2}UTF-8${2}', $xml, 1) ?? $xml;
	}

	/**
	 * The bytes converted to UTF-8.
	 *
	 * @param string $bytes the entry
	 * @param string $charset its charset, an mbstring name
	 * @return string|null the UTF-8 text, or null when mbstring cannot convert it
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
