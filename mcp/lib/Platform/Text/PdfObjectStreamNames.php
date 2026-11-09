<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Text;

/**
 * The objects of a PDF object stream with every name written so that smalot/pdfparser reads it whole.
 *
 * The parser reads an object stored in an object stream (PDF 1.5, `/Type /ObjStm`) with its own text grammar, which
 * takes a dictionary key only from `[A-Za-z0-9#._]` and a name value only from `[A-Za-z0-9#.+,-]`. A name holds any
 * regular character (PDF Reference 1.7, §3.2.4), so a key such as `/OCR-YRoWjq_X9VHkizILgXOFGA` stops at the hyphen and
 * the rest of the dictionary is lost. ocrmypdf draws the Tesseract text layer as a Form XObject named that way and
 * pikepdf stores the page in an object stream, so the page loses its XObjects and its text: the parser returns an empty
 * page, and files_read reported "no text layer". Objects outside object streams go through the parser's tokenizer,
 * which reads names correctly, and are not touched here.
 *
 * Before the parser reads an object stream, every character of a name outside `[A-Za-z0-9.]` is written as its `#xx`
 * escape, which both grammars accept and decode back to the same byte (§3.2.4), and the offsets of the stream are
 * written again for the new lengths. Strings, hexadecimal strings and comments are copied unchanged, and a stream whose
 * names need no escape is returned byte for byte, so every file the parser already read reads exactly as before.
 */
final class PdfObjectStreamNames {
	/** Characters that stay as they are in a name: the ones both grammars of the parser accept. */
	private const KEPT = '/^[A-Za-z0-9.]$/';

	/** White-space and delimiter characters, which end a name (PDF Reference 1.7, §3.1.1). */
	private const ENDS_NAME = " \t\n\r\f\0()<>[]{}/%";

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The decoded content of an object stream with its names escaped and its offsets written again.
	 *
	 * The objects are split exactly as the parser splits them: the leading pairs of numbers are the object numbers and
	 * offsets, and each offset counts from the first character after them.
	 *
	 * @param string $content the decoded stream: pairs of object number and offset, then the objects
	 * @return string the same objects in the same order with their names escaped; the content itself when nothing changes
	 */
	public static function escaped(string $content): string {
		if (preg_match('/^((\d+\s+\d+\s*)*)(.*)$/s', $content, $match) !== 1 || $match[1] === '') {
			return $content;
		}
		preg_match_all('/(\d+)\s+(\d+)/', $match[1], $pairs, PREG_SET_ORDER);
		$table = [];
		foreach ($pairs as [, $id, $offset]) {
			$table[(int)$offset] = $id;
		}
		ksort($table);
		$offsets = array_keys($table);
		$objects = $match[3];
		$numbers = $body = '';
		foreach ($offsets as $index => $offset) {
			$next = $offsets[$index + 1] ?? strlen($objects);
			$numbers .= $table[$offset] . ' ' . strlen($body) . ' ';
			$body .= self::names(substr($objects, $offset, $next - $offset));
		}
		return $body === $objects ? $content : rtrim($numbers) . "\n" . $body;
	}

	/**
	 * One object with every name escaped; strings, hexadecimal strings and comments are copied as they are.
	 *
	 * @param string $object the text of one object
	 * @return string the object with its names escaped
	 */
	public static function names(string $object): string {
		$out = '';
		for ($i = 0, $length = strlen($object); $i < $length;) {
			$char = $object[$i];
			if ($char === '(') {
				$end = self::stringEnd($object, $i);
			} elseif ($char === '<') {
				$end = ($object[$i + 1] ?? '') === '<' ? $i + 2 : self::until($object, $i, '>') + 1;
			} elseif ($char === '%') {
				$end = self::until($object, $i, "\r\n");
			} elseif ($char === '/') {
				$end = self::until($object, $i + 1, self::ENDS_NAME);
				$out .= '/' . self::escapedName(substr($object, $i + 1, $end - $i - 1));
				$i = $end;
				continue;
			} else {
				$end = self::until($object, $i + 1, '(</%');
			}
			$out .= substr($object, $i, $end - $i);
			$i = $end;
		}
		return $out;
	}

	/**
	 * A name, without its slash, with every character the parser does not read written as `#xx`.
	 *
	 * @param string $name the characters of the name as written in the file
	 * @return string the name with only `[A-Za-z0-9.]` and `#xx` escapes
	 */
	private static function escapedName(string $name): string {
		$out = '';
		for ($i = 0, $length = strlen($name); $i < $length; $i++) {
			$char = $name[$i];
			if ($char === '#' && ctype_xdigit(substr($name, $i + 1, 2)) && strlen(substr($name, $i + 1, 2)) === 2) {
				$out .= substr($name, $i, 3);
				$i += 2;
			} elseif (preg_match(self::KEPT, $char) === 1) {
				$out .= $char;
			} else {
				$out .= sprintf('#%02X', ord($char));
			}
		}
		return $out;
	}

	/**
	 * The position right after the literal string that starts at a parenthesis, with nested and escaped parentheses.
	 *
	 * @param string $object the text of the object
	 * @param int $start position of the opening parenthesis
	 * @return int position after the closing parenthesis, or the end of the text for an unterminated string
	 */
	private static function stringEnd(string $object, int $start): int {
		$depth = 0;
		for ($i = $start, $length = strlen($object); $i < $length; $i++) {
			$char = $object[$i];
			if ($char === '\\') {
				$i++;
			} elseif ($char === '(') {
				$depth++;
			} elseif ($char === ')' && --$depth === 0) {
				return $i + 1;
			}
		}
		return $length;
	}

	/**
	 * The first position at or after a start that holds one of the given characters.
	 *
	 * @param string $object the text of the object
	 * @param int $start position where the search starts
	 * @param string $characters the characters searched for
	 * @return int that position, or the end of the text when none follows
	 */
	private static function until(string $object, int $start, string $characters): int {
		return $start + strcspn($object, $characters, min($start, strlen($object)));
	}
}
