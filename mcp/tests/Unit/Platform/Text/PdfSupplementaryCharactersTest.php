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
	 * An incremental bfrange adds its offset to the last code unit of a supplementary destination, which the parser alone
	 * reads as one number on every PHP version; past the last low surrogate the parser's value stays.
	 *
	 * @return void
	 */
	public function testIncrementalRangesGiveConsecutiveSupplementaryCharacters(): void {
		$this->assertSame("\u{1F600}\u{1F601}", ExtractedText::utf8(self::restored(' 1 beginbfrange <0001> <0002> <D83DDE00> endbfrange', '00010002')->getText()));
		$this->assertSame("A\u{1F600}", ExtractedText::utf8(self::restored(' 1 beginbfrange <0001> <0001> <0041D83DDE00> endbfrange', '0001')->getText()));
		$sections = ' 1 beginbfrange <0001> <0002> <D83DDFFF> endbfrange';
		$loaded = self::font((new Parser())->parseContent(BrokenTextSamples::pdfMapping($sections, '0001')))->loadTranslateTable();
		$restored = self::font(self::restored($sections, '0001'))->loadTranslateTable();
		$this->assertSame("\u{1F7FF}", $restored[1]);
		$this->assertSame($loaded[2], $restored[2]);
	}

	/**
	 * A later definition of the same code replaces an earlier pair, in the parser's order: every bfchar section first,
	 * then every bfrange section. A later pair replaces an earlier BMP definition.
	 *
	 * @return void
	 */
	public function testTheEffectiveDefinitionWins(): void {
		$superseded = [
			'later bfchar BMP' => ' 2 beginbfchar <0001> <D83DDE00> <0001> <0041> endbfchar',
			'later bfchar lone surrogate' => ' 2 beginbfchar <0001> <D83DDE00> <0001> <D83D> endbfchar',
			'later bfchar section' => ' 1 beginbfchar <0001> <D83DDE00> endbfchar 1 beginbfchar <0001> <0041> endbfchar',
			'incremental bfrange' => ' 1 beginbfchar <0001> <D83DDE00> endbfchar 1 beginbfrange <0001> <0001> <0041> endbfrange',
			'bfrange written before bfchar, applied after it' => ' 1 beginbfrange <0001> <0001> <0041> endbfrange 1 beginbfchar <0001> <D83DDE00> endbfchar',
			'bfrange array' => ' 1 beginbfchar <0001> <D83DDE00> endbfchar 1 beginbfrange <0001> <0001> [<0041>] endbfrange',
			'later bfrange' => ' 2 beginbfrange <0001> <0002> <D83DDE00> <0001> <0001> <0041> endbfrange',
		];
		foreach ($superseded as $case => $sections) {
			$loaded = self::font((new Parser())->parseContent(BrokenTextSamples::pdfMapping($sections, '0001')))->loadTranslateTable();
			$restored = self::font(self::restored($sections, '0001'))->loadTranslateTable();
			$this->assertSame($loaded[1], $restored[1], $case);
			$this->assertStringNotContainsString("\u{1F600}", ExtractedText::utf8($loaded[1]), $case);
		}
		$text = self::restored(' 2 beginbfchar <0001> <0041> <0001> <D83DDE00> endbfchar 1 beginbfrange <0002> <0002> <0042> endbfrange', '00010002');
		$this->assertSame("\u{1F600}B", ExtractedText::utf8($text->getText()));
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
