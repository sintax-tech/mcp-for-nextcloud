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
 * character is lost before the text is decoded. In an incremental bfrange (`<0001> <0002> <D83DDE00>`) the parser adds
 * the offset to the whole destination taken as one number, so the range never gave its characters on any PHP version.
 *
 * After parsing and before the text is read, the map is read again with the parser's grammar and in the parser's order:
 * every bfchar section, then every bfrange section, a later definition of a code replacing the earlier one (Adobe
 * TN 5014, overlapping ranges). A code whose effective definition holds a valid surrogate pair gets its whole text,
 * through the parser's public {@see \Smalot\PdfParser\Font::setTable()}; an incremental range increments the last byte of
 * the destination (PDF Reference 1.6, §5.9.2), and a code whose last byte would pass FF, which the specification leaves
 * undefined, keeps the parser's value. Codes the parser did not load are
 * never added, and every other code (no surrogate, a lone surrogate, or an earlier pair replaced by such a definition)
 * keeps the parser's value, so a map without surrogates reads exactly as before.
 */
final class PdfSupplementaryCharacters {
	/** One `<source> <destination>` entry of a bfchar section, as the parser matches it. */
	private const BFCHAR = '/<([0-9A-F]+)> *<([0-9A-F]+)>[ \r\n]+/i';

	/** One `<first> <last> <destination>` or `<first> <last> [<destination> ...]` entry of a bfrange section, as the parser matches it. */
	private const BFRANGE = '/<([0-9A-F]+)> *<([0-9A-F]+)> *(<[0-9A-F]+>|\[[\r\n<>0-9A-F ]+\])[ \r\n]+/i';

	/** Longest character code read, in hexadecimal digits: four bytes, the most a CMap code space has. */
	private const CODE_DIGITS = 8;

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
			// The map is read with the parser's grammar, so its own table holds every code found here. The intersection
			// still keeps the codes to those of the table the font decodes with, which a caller may have replaced through
			// the public setTable() or another parser version may read differently: a code it does not decode is never added.
			$font->setTable(array_replace($table, array_intersect_key($pairs, $table)));
		}
	}

	/**
	 * The text of every code whose effective definition holds a valid surrogate pair.
	 *
	 * @param string $cmap the decoded ToUnicode CMap
	 * @return array<int, string> character code => UTF-8 text
	 */
	private static function pairs(string $cmap): array {
		$pairs = [];
		foreach (self::entries('bfchar', self::BFCHAR, $cmap) as [, $source, $destination]) {
			self::define($pairs, self::code($source), self::text(self::units($destination)));
		}
		foreach (self::entries('bfrange', self::BFRANGE, $cmap) as [, $first, $last, $destination]) {
			$first = self::code($first);
			if (str_starts_with($destination, '<')) {
				self::range($pairs, $first, self::code($last), self::units(substr($destination, 1, -1)));
				continue;
			}
			preg_match_all('/<([0-9A-F]+)>/i', $destination, $strings);
			foreach ($strings[1] as $position => $string) {
				self::define($pairs, $first === null ? null : $first + $position, self::text(self::units($string)));
			}
		}
		return $pairs;
	}

	/**
	 * The entries of every section of one kind, in the order of the map.
	 *
	 * @param string $kind `bfchar` or `bfrange`
	 * @param string $entry the pattern of one entry
	 * @param string $cmap the decoded ToUnicode CMap
	 * @return list<array<int, string>> the matches of each entry
	 */
	private static function entries(string $kind, string $entry, string $cmap): array {
		$entries = [];
		preg_match_all('/begin' . $kind . '(.*?)end' . $kind . '/s', $cmap, $sections);
		foreach ($sections[1] as $section) {
			preg_match_all($entry, $section, $matches, PREG_SET_ORDER);
			array_push($entries, ...$matches);
		}
		return $entries;
	}

	/**
	 * Records the effective definition of one code: its text when it holds a pair, otherwise the parser's value.
	 *
	 * @param array<int, string> $pairs the codes with a pair so far
	 * @param int|null $code the character code, null when it is too long to be one
	 * @param string|null $text the text with a pair, null for any other definition
	 * @return void
	 */
	private static function define(array &$pairs, ?int $code, ?string $text): void {
		if ($code === null) {
			return;
		}
		if ($text === null) {
			unset($pairs[$code]);
		} else {
			$pairs[$code] = $text;
		}
	}

	/**
	 * Records an incremental range: every code of it replaces its earlier definition, and gets the destination with its
	 * last byte incremented by the offset when that text holds a valid pair; codes past FF in that byte are not given one.
	 *
	 * @param array<int, string> $pairs the codes with a pair so far
	 * @param int|null $first the first code of the range
	 * @param int|null $last the last code of the range
	 * @param list<int>|null $units the destination's code units
	 * @return void
	 */
	private static function range(array &$pairs, ?int $first, ?int $last, ?array $units): void {
		if ($first === null || $last === null) {
			return;
		}
		foreach (array_keys($pairs) as $code) {
			if ($code >= $first && $code <= $last) {
				unset($pairs[$code]);
			}
		}
		if ($units === null || self::text($units) === null) {
			return;
		}
		$end = count($units) - 1;
		for ($offset = 0, $steps = min($last - $first, 0xFF - ($units[$end] & 0xFF)); $offset <= $steps; $offset++) {
			$shifted = $units;
			$shifted[$end] += $offset;
			self::define($pairs, $first + $offset, self::text($shifted));
		}
	}

	/**
	 * A character code written in hexadecimal.
	 *
	 * @param string $hex the code's digits
	 * @return int|null the code, null when longer than a CMap code
	 */
	private static function code(string $hex): ?int {
		return strlen($hex) <= self::CODE_DIGITS ? (int)hexdec($hex) : null;
	}

	/**
	 * The UTF-16 code units of a destination.
	 *
	 * @param string $hex the destination's digits
	 * @return list<int>|null the code units, null when the digits are not whole code units
	 */
	private static function units(string $hex): ?array {
		if ($hex === '' || strlen($hex) % 4 !== 0) {
			return null;
		}
		return array_map(static fn (string $unit): int => (int)hexdec($unit), str_split($hex, 4));
	}

	/**
	 * Code units holding at least one surrogate pair and no lone surrogate, as UTF-8.
	 *
	 * @param list<int>|null $units UTF-16 code units
	 * @return string|null the UTF-8 text, null without a pair or with a lone surrogate
	 */
	private static function text(?array $units): ?string {
		if ($units === null) {
			return null;
		}
		$text = '';
		$paired = false;
		for ($i = 0, $count = count($units); $i < $count; $i++) {
			$unit = $units[$i];
			if ($unit >= 0xD800 && $unit <= 0xDBFF && ($units[$i + 1] ?? 0) >= 0xDC00 && $units[$i + 1] <= 0xDFFF) {
				$unit = 0x10000 + (($unit - 0xD800) << 10) + ($units[++$i] - 0xDC00);
				$paired = true;
			} elseif ($unit >= 0xD800 && $unit <= 0xDFFF) {
				return null;
			}
			$text .= (string)mb_chr($unit, 'UTF-8');
		}
		return $paired ? $text : null;
	}
}
