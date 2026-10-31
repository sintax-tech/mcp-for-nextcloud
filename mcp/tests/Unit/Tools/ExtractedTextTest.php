<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tools\ExtractedText;
use PHPUnit\Framework\TestCase;

/**
 * Every output of {@see ExtractedText::utf8()} is valid UTF-8 that json_encode accepts without a substitution flag, and
 * what can be read back (surrogate pairs, single-byte text) is read back instead of replaced.
 */
final class ExtractedTextTest extends TestCase {
	/**
	 * Valid UTF-8 keeps every character, line breaks and tabs included.
	 *
	 * @return void
	 */
	public function testValidTextIsKept(): void {
		$text = "Relatório\tação\r\n\u{1F600} 漢字\fpágina 2";
		$this->assertSame($text, ExtractedText::utf8($text));
	}

	/**
	 * A surrogate pair written as CESU-8 becomes the character it encodes; a lone half becomes U+FFFD.
	 *
	 * @return void
	 */
	public function testSurrogatesWrittenAsCesuAreRepaired(): void {
		$this->assertSame("A \u{1F600} b", self::checked("A \xED\xA0\xBD\xED\xB8\x80 b"));
		$this->assertSame("a long line of plain text \u{FFFD} end", self::checked("a long line of plain text \xED\xA0\xBD end"));
		$this->assertSame("\u{FFFD}\u{1F600}", self::checked("\xED\xB8\x80\xED\xA0\xBD\xED\xB8\x80"));
	}

	/**
	 * Text without any multibyte sequence is read as Windows-1252, quotes and the euro sign included.
	 *
	 * @return void
	 */
	public function testSingleByteTextIsReadAsWindows1252(): void {
		$this->assertSame('Relatório de ação “final” €', self::checked("Relat\xF3rio de a\xE7\xE3o \x93final\x94 \x80"));
	}

	/**
	 * A stray byte inside UTF-8 text is replaced, never guessed: it is a broken sequence, not a Latin-1 letter.
	 *
	 * @return void
	 */
	public function testStrayBytesInUtf8TextAreReplaced(): void {
		$this->assertSame("ação \u{FFFD} fim", self::checked("ação \xE1 fim"));
		$this->assertSame("ok\u{FFFD}\u{FFFD}", self::checked("ok\xE2\x82"));
	}

	/**
	 * Binary content is never turned into letters: with a NUL byte or mostly high bytes every invalid byte is U+FFFD.
	 *
	 * @return void
	 */
	public function testBinaryIsNeverGuessedIntoLetters(): void {
		$this->assertSame("PK\u{FFFD}\u{FFFD}", self::checked("PK\x00\xE1\xF3"));
		$this->assertSame("\u{FFFD}\u{FFFD}a\u{FFFD}", self::checked("\xE1\xF3a\xFE"));
	}

	/**
	 * Control characters are removed, C1 included, while tab, line feed, form feed and carriage return stay.
	 *
	 * @return void
	 */
	public function testControlCharactersAreRemoved(): void {
		$this->assertSame("a\tb\nc\rd\fe", self::checked("a\x00\tb\x07\nc\x1B\rd\x7F\fe\u{0085}"));
	}

	/**
	 * The cleaned text, after asserting it is valid UTF-8 that json_encode accepts as is.
	 *
	 * @param string $raw parser output
	 * @return string the cleaned text
	 */
	private static function checked(string $raw): string {
		$text = ExtractedText::utf8($raw);
		self::assertTrue(mb_check_encoding($text, 'UTF-8'));
		self::assertSame($text, json_decode(json_encode($text, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
		return $text;
	}
}
