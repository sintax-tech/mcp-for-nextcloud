<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Text;

use Smalot\PdfParser\Document;
use Smalot\PdfParser\PDFObject;

/**
 * Characters beyond U+FFFF (emoji, historic scripts, rare CJK) of a parsed PDF, kept on every PHP version.
 *
 * A font's ToUnicode map writes such a character as a UTF-16 surrogate pair (`<0003> <D83DDE00>`). smalot/pdfparser
 * converts each half on its own with mbstring's HTML-ENTITIES decoder: up to PHP 8.3 each half came out as CESU-8, which
 * {@see ExtractedText} reads back, but since PHP 8.4.27 and 8.5.12 mbstring writes "?" for a lone surrogate and the
 * character is lost before the text is decoded.
 *
 * After parsing and before the text is read, every ToUnicode entry the parser loaded whose destination holds a valid
 * surrogate pair gets the UTF-8 of the whole destination, through the parser's public {@see \Smalot\PdfParser\Font::setTable()}.
 * Entries the parser did not load are never added, and destinations without a surrogate or with a lone surrogate keep the
 * parser's value, so the text read on PHP 8.2 and 8.3 is the same as before.
 */
final class PdfSupplementaryCharacters {
	/** A ToUnicode destination of whole UTF-16 code units holding a high surrogate. */
	private const PAIR_DESTINATION = '/^(?:[0-9A-F]{4})*D[89AB][0-9A-F]{2}(?:[0-9A-F]{4})+$/i';

	/** One `<source> <destination>` entry of a bfchar section. */
	private const BFCHAR = '/<([0-9A-F]+)>\s*<([0-9A-F]+)>/i';

	/** One `<first> <last> [<destination> ...]` entry of a bfrange section. */
	private const BFRANGE_ARRAY = '/<([0-9A-F]+)>\s*<[0-9A-F]+>\s*\[([^\]]*)\]/i';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Gives every font of the document the characters its ToUnicode map writes as surrogate pairs.
	 *
	 * @param Document $document the parsed PDF, before its text is read
	 * @return void
	 */
	public static function restore(Document $document): void {
		foreach ($document->getFonts() as $font) {
			$map = $font->has('ToUnicode') ? $font->get('ToUnicode') : null;
			if (!$map instanceof PDFObject) {
				continue;
			}
			$pairs = self::pairs((string)$map->getContent());
			if ($pairs === []) {
				continue;
			}
			$table = $font->loadTranslateTable();
			$font->setTable(array_replace($table, array_intersect_key($pairs, $table)));
		}
	}

	/**
	 * The UTF-8 of every bfchar and bfrange array destination holding a valid surrogate pair, by character code.
	 *
	 * @param string $cmap the decoded ToUnicode CMap
	 * @return array<int, string> character code => UTF-8 text
	 */
	private static function pairs(string $cmap): array {
		$pairs = [];
		preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $sections);
		foreach ($sections[1] as $section) {
			preg_match_all(self::BFCHAR, $section, $entries, PREG_SET_ORDER);
			foreach ($entries as [, $source, $destination]) {
				self::add($pairs, (int)hexdec($source), $destination);
			}
		}
		preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $sections);
		foreach ($sections[1] as $section) {
			preg_match_all(self::BFRANGE_ARRAY, $section, $entries, PREG_SET_ORDER);
			foreach ($entries as [, $first, $destinations]) {
				preg_match_all('/<([0-9A-F]+)>/i', $destinations, $strings);
				foreach ($strings[1] as $position => $destination) {
					self::add($pairs, (int)hexdec($first) + $position, $destination);
				}
			}
		}
		return $pairs;
	}

	/**
	 * Keeps the destination of one character code when it holds a valid surrogate pair.
	 *
	 * @param array<int, string> $pairs the characters found so far, by character code
	 * @param int $code the character code
	 * @param string $destination the destination, in hexadecimal UTF-16BE
	 * @return void
	 */
	private static function add(array &$pairs, int $code, string $destination): void {
		if (preg_match(self::PAIR_DESTINATION, $destination) !== 1) {
			return;
		}
		$text = self::utf8($destination);
		if ($text !== null) {
			$pairs[$code] = $text;
		}
	}

	/**
	 * A UTF-16BE destination as UTF-8, or null when a surrogate has no other half.
	 *
	 * @param string $destination whole UTF-16 code units, in hexadecimal
	 * @return string|null the UTF-8 text
	 */
	private static function utf8(string $destination): ?string {
		$text = '';
		$units = array_map('hexdec', str_split($destination, 4));
		for ($i = 0, $count = count($units); $i < $count; $i++) {
			$unit = (int)$units[$i];
			if ($unit >= 0xD800 && $unit <= 0xDBFF && isset($units[$i + 1]) && $units[$i + 1] >= 0xDC00 && $units[$i + 1] <= 0xDFFF) {
				$unit = 0x10000 + (($unit - 0xD800) << 10) + ((int)$units[++$i] - 0xDC00);
			} elseif ($unit >= 0xD800 && $unit <= 0xDFFF) {
				return null;
			}
			$text .= (string)mb_chr($unit, 'UTF-8');
		}
		return $text;
	}
}
