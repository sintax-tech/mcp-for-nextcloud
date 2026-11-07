<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Text;

use OCA\Mcp\Platform\Text\ExtractedText;
use OCA\Mcp\Platform\Text\PdfSupplementaryCharacters;
use OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Parser;

/**
 * {@see PdfSupplementaryCharacters} gives back the characters beyond U+FFFF of a ToUnicode map on every PHP version, and
 * changes nothing else in the table the parser loaded.
 */
final class PdfSupplementaryCharactersTest extends TestCase {
	/**
	 * Surrogate pairs of bfrange arrays come back whole, alone or after another character.
	 *
	 * @return void
	 */
	public function testBfrangeArraysKeepTheirSupplementaryCharacters(): void {
		$document = self::restored(' 1 beginbfrange <0001> <0002> [<0041D83DDE00> <D83CDF89>] endbfrange', '00010002');
		$this->assertSame("A\u{1F600}\u{1F389}", ExtractedText::utf8($document->getText()));
	}

	/**
	 * Destinations without a valid pair keep the parser's value, and entries the parser did not load are not added.
	 *
	 * @return void
	 */
	public function testOtherEntriesAreLeftToTheParser(): void {
		$sections = ' 2 beginbfchar <0001> <00E1> <0002> <D83D0041> endbfchar 1 beginbfchar <0003> <D83DDE00>endbfchar';
		$loaded = self::font((new Parser())->parseContent(BrokenTextSamples::pdfMapping($sections, '0001')))->loadTranslateTable();
		$restored = self::font(self::restored($sections, '0001'))->loadTranslateTable();
		$this->assertSame($loaded, $restored);
		$this->assertArrayNotHasKey(3, $restored);
	}

	/**
	 * The parsed sample PDF after the restore.
	 *
	 * @param string $sections the bfchar and bfrange sections of the CMap
	 * @param string $glyphs the glyph codes shown, in hexadecimal
	 * @return Document the document, ready to read
	 */
	private static function restored(string $sections, string $glyphs): Document {
		$document = (new Parser())->parseContent(BrokenTextSamples::pdfMapping($sections, $glyphs));
		PdfSupplementaryCharacters::restore($document);
		return $document;
	}

	/**
	 * The only font of the sample PDF.
	 *
	 * @param Document $document the parsed sample
	 * @return Font the Type0 font with the ToUnicode map
	 */
	private static function font(Document $document): Font {
		$fonts = $document->getFonts();
		self::assertCount(1, $fonts);
		return reset($fonts);
	}
}
