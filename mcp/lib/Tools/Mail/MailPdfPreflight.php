<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Refuses, from the raw bytes and before smalot/pdfparser sees them, a PDF the parser could not decode within the
 * attachment budget, reading the file with the parser's own lexicon (Smalot\PdfParser\RawData\RawDataParser of the
 * shipped release):
 * - a name runs to the first of NUL, TAB, LF, VT, FF, CR, space, ( ) < > [ ] { } / %, at most 256 bytes, and its #xx
 *   escapes are never decoded; white space between tokens is NUL, TAB, LF, FF, CR and space, and % starts a comment to
 *   the end of the line;
 * - LZWDecode and RunLengthDecode, which the parser expands without limit, can only be named by those very bytes, so
 *   any occurrence of them refuses the file, whatever separates or follows them (NUL, an array, a comment); a name
 *   that spells them, or their abbreviations, with #xx escapes is refused too, although the parser would not decode it;
 * - a /Filter key whose value, past white space, is not a name or an array of names takes its filters from an indirect
 *   object the raw bytes do not show (possibly inside an object stream), and refuses the file;
 * - /Encrypt refuses the file as encrypted;
 * - more than {@see self::MAX_STREAMS} streams (each carries the `stream` keyword followed by an end of line, as the
 *   parser reads it; object streams cannot hold streams) refuse the file.
 * The decoders themselves are bounded too ({@see MailPdfFilters}); this check makes the refusal happen before parsing.
 */
final class MailPdfPreflight {
	/** Most stream objects in a PDF parsed. */
	public const MAX_STREAMS = 128;

	/** What ends a name for the parser. */
	private const NAME_DELIMITERS = "\x00\x09\x0a\x0b\x0c\x0d\x20()<>[]{}/%";

	/** White space between tokens for the parser. */
	private const WHITE_SPACE = "\x00\x09\x0a\x0c\x0d\x20";

	/** Longest name the parser reads, in bytes. */
	private const NAME_BYTES = 256;

	/** Filters the parser decodes without a size limit, as it names them. */
	private const UNBOUNDED = ['LZWDecode', 'RunLengthDecode'];

	/** The same filters and their abbreviations, as a name may spell them once its escapes are decoded. */
	private const UNBOUNDED_NAMES = ['LZWDecode', 'RunLengthDecode', 'LZW', 'RL'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Refuses a PDF outside the budget.
	 *
	 * @param string $bytes the raw PDF
	 * @return void
	 * @throws ToolFailure when the PDF is encrypted or could not be decoded within the budget
	 */
	public static function check(string $bytes): void {
		$names = self::names($bytes);
		if (in_array('Encrypt', $names, true)) {
			throw new ToolFailure(MailAttachmentMessages::encrypted());
		}
		foreach (self::UNBOUNDED as $filter) {
			if (str_contains($bytes, $filter)) {
				throw new ToolFailure(MailAttachmentMessages::unreadable());
			}
		}
		$streams = preg_match_all('~(?<!end)stream *\r?\n~', $bytes);
		if ($streams === false || $streams > self::MAX_STREAMS || array_intersect(self::UNBOUNDED_NAMES, $names) !== [] || self::indirectFilter($bytes)) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
	}

	/**
	 * Every name of the raw file as the parser reads it, then with its #xx escapes decoded.
	 *
	 * @param string $bytes the raw PDF
	 * @return list<string> the names, without their slash
	 */
	private static function names(string $bytes): array {
		$names = [];
		for ($at = strpos($bytes, '/'); $at !== false; $at = strpos($bytes, '/', $at + 1)) {
			$name = substr($bytes, $at + 1, strcspn($bytes, self::NAME_DELIMITERS, $at + 1, self::NAME_BYTES));
			$names[$name] = true;
			$names[(string)preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $hex): string => chr((int)hexdec($hex[1])), $name)] = true;
		}
		return array_map('strval', array_keys($names));
	}

	/**
	 * Whether a /Filter key takes its value from anything but a name or an array of names.
	 *
	 * @param string $bytes the raw PDF
	 * @return bool true when some /Filter value, past white space and comments, starts with neither / nor [
	 */
	private static function indirectFilter(string $bytes): bool {
		$length = strlen($bytes);
		for ($at = strpos($bytes, '/Filter'); $at !== false; $at = strpos($bytes, '/Filter', $at + 1)) {
			$next = $at + 7;
			if ($next < $length && strcspn($bytes[$next], self::NAME_DELIMITERS) !== 0) {
				continue;
			}
			$next += strspn($bytes, self::WHITE_SPACE, $next);
			while ($next < $length && $bytes[$next] === '%') {
				$next += strcspn($bytes, "\r\n", $next);
				$next += strspn($bytes, self::WHITE_SPACE, $next);
			}
			if ($next >= $length || ($bytes[$next] !== '/' && $bytes[$next] !== '[')) {
				return true;
			}
		}
		return false;
	}
}
