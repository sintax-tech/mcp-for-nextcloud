<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Text;

use OCA\Mcp\Platform\Text\PdfTextParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * {@see PdfTextParser} reads the text layer that ocrmypdf adds to a scan (issue #31): the page that draws it is stored
 * in an object stream and names its Form XObject `/OCR-…`. Files without object streams read exactly as before.
 */
final class PdfTextParserTest extends TestCase {
	/** @return iterable<string, array{string}> scans made searchable by ocrmypdf with `--output-type pdf` */
	public static function ocrScans(): iterable {
		yield 'ocrmypdf 15.2 with Tesseract 5.3' => ['ocrmypdf15-tesseract5.pdf'];
		yield 'ocrmypdf 13.4 with Tesseract 4.1' => ['ocrmypdf13-tesseract4.pdf'];
	}

	/**
	 * The text layer of the scan comes back.
	 *
	 * @param string $fixture file name in tests/fixtures/pdf-ocr
	 * @return void
	 */
	#[DataProvider('ocrScans')]
	public function testTheTesseractTextLayerOfAnOcrmypdfScanIsRead(string $fixture): void {
		$document = (new PdfTextParser())->parseContent(self::fixture('pdf-ocr/' . $fixture));
		$this->assertSame('Scanned sample text 4711', trim($document->getText()));
		$this->assertSame(1, count($document->getPages()));
	}

	/**
	 * A PDF without object streams gives the same text and the same objects as the library parser.
	 *
	 * @return void
	 */
	public function testAFileWithoutObjectStreamsReadsAsBefore(): void {
		$bytes = self::fixture('sample.pdf');
		$library = (new Parser())->parseContent($bytes);
		$ours = (new PdfTextParser())->parseContent($bytes);
		$this->assertSame($library->getText(), $ours->getText());
		$this->assertSame(array_keys($library->getObjects()), array_keys($ours->getObjects()));
	}

	/**
	 * @param string $name path under tests/fixtures
	 * @return string the file content
	 */
	private static function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/' . $name);
	}
}
