<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Text;

use OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples;
use OCA\Mcp\Platform\Text\ExtractedText;
use OCA\Mcp\Platform\Text\XmlCharset;
use PHPUnit\Framework\TestCase;

/**
 * A document XML entry is brought to UTF-8 from the charset it declares, before its entities are decoded, so legacy
 * letters and entities end up as the same valid UTF-8 (issue #25 review, F2); nothing undeclared is converted.
 */
final class XmlCharsetTest extends TestCase {
	/**
	 * A Windows-1252 entry with entities: the letters are converted, the entities kept for the decoding that follows,
	 * and the declaration now names UTF-8.
	 *
	 * @return void
	 */
	public function testDeclaredWindows1252IsConvertedBeforeEntities(): void {
		$xml = '<?xml version="1.0" encoding="Windows-1252"?><w:p>' . BrokenTextSamples::latin() . '</w:p>';
		$converted = XmlCharset::toUtf8($xml);
		$this->assertSame('<?xml version="1.0" encoding="UTF-8"?><w:p>Relatório de ação &#x20AC; caf&#233;</w:p>', $converted);
		$text = ExtractedText::utf8(html_entity_decode(strip_tags($converted), ENT_QUOTES | ENT_XML1, 'UTF-8'));
		$this->assertSame(BrokenTextSamples::LATIN_TEXT, $text);
	}

	/**
	 * Other single-byte charsets mbstring knows are read too, with the declaration in single quotes.
	 *
	 * @return void
	 */
	public function testOtherDeclaredCharsetsAreConverted(): void {
		$xml = "<?xml version='1.0' encoding='ISO-8859-1' standalone='yes'?><p>a\xE7\xE3o</p>";
		$this->assertSame("<?xml version='1.0' encoding='UTF-8' standalone='yes'?><p>ação</p>", XmlCharset::toUtf8($xml));
	}

	/**
	 * A UTF-16 entry announced by its byte order mark is converted, without the mark.
	 *
	 * @return void
	 */
	public function testUtf16WithAByteOrderMarkIsConverted(): void {
		$this->assertSame('<p>ação</p>', XmlCharset::toUtf8("\xFF\xFE" . mb_convert_encoding('<p>ação</p>', 'UTF-16LE', 'UTF-8')));
		$this->assertSame('<p>ação</p>', XmlCharset::toUtf8("\xFE\xFF" . mb_convert_encoding('<p>ação</p>', 'UTF-16BE', 'UTF-8')));
	}

	/**
	 * Aliases of the text charsets are read as the charset they name.
	 *
	 * @return void
	 */
	public function testCharsetAliasesAreConverted(): void {
		foreach (['latin1', 'cp1252', 'WINDOWS_1252', 'iso8859-1'] as $name) {
			$this->assertSame('<?xml version="1.0" encoding="UTF-8"?><p>ação</p>', XmlCharset::toUtf8('<?xml version="1.0" encoding="' . $name . "\"?><p>a\xE7\xE3o</p>"), $name);
		}
	}

	/**
	 * Only the declaration, whose target is exactly `xml`, names the charset: another processing instruction at the start,
	 * such as a stylesheet, or a pseudo-attribute merely ending in "encoding", converts nothing (review round 2, F4).
	 *
	 * @return void
	 */
	public function testOnlyTheXmlDeclarationNamesTheCharset(): void {
		foreach (['<?xml-stylesheet type="text/xsl" encoding="ISO-8859-1" href="view.xsl"?><p>ação</p>',
			'<?xmlfoo encoding="ISO-8859-1"?><p>ação</p>', '<?xml version="1.0" myencoding="ISO-8859-1"?><p>ação</p>'] as $xml) {
			$this->assertSame($xml, XmlCharset::toUtf8($xml));
		}
	}

	/**
	 * Transfer encodings mbstring accepts (and UTF-16 named by a declaration readable as ASCII) are no text charset of an
	 * entry: the bytes stay as they are, never wiped, scrambled or entity-decoded early (review round 2, F5).
	 *
	 * @return void
	 */
	public function testTransferEncodingsAreNotCharsets(): void {
		foreach (['UUENCODE', 'BASE64', 'HTML-ENTITIES', 'Quoted-Printable', '7bit', '8bit', 'UTF-7', 'UTF-16', 'UCS-2'] as $name) {
			$xml = '<?xml version="1.0" encoding="' . $name . '"?><p>a&amp;b &#233; =41</p>';
			$this->assertSame($xml, XmlCharset::toUtf8($xml), $name);
		}
	}

	/**
	 * A UTF-16 entry with a declaration comes out declaring UTF-8, so a second pass changes nothing; every conversion is
	 * idempotent (review round 2, F6).
	 *
	 * @return void
	 */
	public function testConvertedEntriesDeclareUtf8AndConvertOnce(): void {
		$utf16 = "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><p>ação</p>', 'UTF-16LE', 'UTF-8');
		$this->assertSame('<?xml version="1.0" encoding="UTF-8"?><p>ação</p>', XmlCharset::toUtf8($utf16));
		foreach ([$utf16, '<?xml version="1.0" encoding="Windows-1252"?><p>' . BrokenTextSamples::latin() . '</p>', "<p>a\xE7\xE3o</p>"] as $xml) {
			$once = XmlCharset::toUtf8($xml);
			$this->assertSame($once, XmlCharset::toUtf8($once));
		}
	}

	/**
	 * UTF-8, no declaration and a charset mbstring does not know leave the bytes as they are.
	 *
	 * @return void
	 */
	public function testNothingUndeclaredOrUnknownIsConverted(): void {
		foreach (['<?xml version="1.0" encoding="UTF-8"?><p>ação</p>', "<p>a\xE7\xE3o</p>", '<?xml version="1.0"?><p>' . "a\xE7\xE3o</p>",
			'<?xml version="1.0" encoding="x-unknown-charset"?><p>' . "a\xE7\xE3o</p>", "<p>x</p><?xml encoding=\"Windows-1252\"?>\xE7"] as $xml) {
			$this->assertSame($xml, XmlCharset::toUtf8($xml));
		}
	}
}
