<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Text;

use OCA\Mcp\Platform\Text\PdfObjectStreamNames;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Header;

/**
 * {@see PdfObjectStreamNames} writes every name of an object stream so that the parser reads it whole, keeps strings,
 * hexadecimal strings and comments as they are, and keeps the offsets of the stream pointing at each object.
 */
final class PdfObjectStreamNamesTest extends TestCase {
	/** The page dictionary ocrmypdf writes, with the Form XObject that draws the Tesseract text layer. */
	private const OCR_PAGE = '<< /Contents [ 11 0 R 12 0 R ] /Resources << /Font << >> /ProcSet 7 0 R'
		. ' /XObject << /OCR-YRoWjq_X9VHkizILgXOFGA 13 0 R /image 14 0 R >> >> /Type /Page >>';

	/**
	 * A key with a hyphen cut the dictionary for the parser; escaped, every key and every resource name is read.
	 *
	 * @return void
	 */
	public function testTheParserReadsTheEscapedOcrPageWhole(): void {
		$document = new Document();
		$this->assertSame([], array_keys(Header::parse(self::OCR_PAGE, $document)->get('Resources')->get('XObject')->getElements()));
		$header = Header::parse(PdfObjectStreamNames::names(self::OCR_PAGE), $document);
		$this->assertSame(['Contents', 'Resources', 'Type'], array_keys($header->getElements()));
		$this->assertSame(['OCR-YRoWjq_X9VHkizILgXOFGA', 'image'], array_keys($header->get('Resources')->get('XObject')->getElements()));
	}

	/**
	 * Keys and name values outside `[A-Za-z0-9.]` are escaped and decode back to the same name; a valid escape stays, a
	 * bare number sign is escaped itself.
	 *
	 * @return void
	 */
	public function testNamesAreEscapedAndDecodeBackToTheSameName(): void {
		$this->assertSame('<< /f#2D0#2D0 5 0 R /BaseFont /ABCDEF#2BSome#5FFont#2CBold /A#20B /C#23 /x.1 >>',
			PdfObjectStreamNames::names('<< /f-0-0 5 0 R /BaseFont /ABCDEF+Some_Font,Bold /A#20B /C# /x.1 >>'));
		$header = Header::parse(PdfObjectStreamNames::names('<< /f-0-0 5 /BaseFont /ABCDEF+Some_Font,Bold /Next /A-b >>'), new Document());
		$this->assertSame(['f-0-0', 'BaseFont', 'Next'], array_keys($header->getElements()));
		$this->assertSame('ABCDEF+Some_Font,Bold', $header->get('BaseFont')->getContent());
		$this->assertSame('A-b', $header->get('Next')->getContent());
	}

	/**
	 * Literal strings (nested and escaped parentheses), hexadecimal strings and comments are copied byte for byte.
	 *
	 * @return void
	 */
	public function testStringsHexStringsAndCommentsAreKept(): void {
		$object = "<< /Title (a/b-c \\) (x/y_z)) /ID [<41-42> <4344>] /K-1 1 >> % /not-a-name\n[ /a-b ]";
		$this->assertSame("<< /Title (a/b-c \\) (x/y_z)) /ID [<41-42> <4344>] /K#2D1 1 >> % /not-a-name\n[ /a#2Db ]",
			PdfObjectStreamNames::names($object));
	}

	/**
	 * The stream is written again with the offsets of the escaped objects, in the order of the original offsets.
	 *
	 * @return void
	 */
	public function testOffsetsPointAtEachEscapedObject(): void {
		$first = '<< /OCR-a 13 0 R >>';
		$second = '<< /Type /Font /f-0-0 1 >>';
		$escaped = PdfObjectStreamNames::escaped('7 ' . strlen($first) . ' 6 0 ' . $first . $second);
		$this->assertSame("6 0 7 21\n<< /OCR#2Da 13 0 R >><< /Type /Font /f#2D0#2D0 1 >>", $escaped);
		[$numbers, $objects] = explode("\n", $escaped, 2);
		$this->assertSame('<< /Type /Font /f#2D0#2D0 1 >>', substr($objects, (int)explode(' ', $numbers)[3]));
	}

	/**
	 * A stream whose names need no escape, and content that is not an object stream, come back unchanged.
	 *
	 * @return void
	 */
	public function testContentWithoutNamesToEscapeIsUnchanged(): void {
		$content = "2 0 3 31\n<< /Type /Catalog /Pages 4 0 R >><< /Count 1 /Kids [ 5 0 R ] >>";
		$this->assertSame($content, PdfObjectStreamNames::escaped($content));
		$this->assertSame('<< /A-b 1 >>', PdfObjectStreamNames::escaped('<< /A-b 1 >>'));
	}
}
