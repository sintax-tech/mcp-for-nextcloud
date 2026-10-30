<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailAttachmentDocument;
use OCA\Mcp\Tools\Mail\MailAttachmentMessages;
use OCA\Mcp\Tools\Mail\MailAttachmentPart;
use OCA\Mcp\Tools\Mail\MailAttachmentText;
use OCA\Mcp\Tools\Mail\MailPdfPreflight;
use OCA\Mcp\Tools\ToolFailure;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * The kinds read as text and everything refused: the content decides between PDF, DOCX, ODT and text, the declared
 * type only admits text, HTML never comes back as markup, and encrypted, damaged or over-budget files are refused with
 * a safe message before or instead of any parser output.
 */
final class MailAttachmentTextTest extends TestCase {
	/** @var list<string> temporary files handed out */
	private array $temporary = [];

	/**
	 * Removes what the temporary file manager handed out; the reader must already have removed it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			$this->assertFileDoesNotExist($path, 'the DOCX/ODT reader leaves no temporary file behind');
		}
	}

	/**
	 * Plain text in its declared charset comes back as UTF-8, without a byte order mark.
	 *
	 * @return void
	 */
	public function testDeclaredTextIsConvertedFromItsCharset(): void {
		$this->assertSame(['kind' => 'text', 'text' => 'Relatório anual'], $this->extract(mb_convert_encoding('Relatório anual', 'ISO-8859-1', 'UTF-8'), 'text/plain', 'iso-8859-1'));
		$this->assertSame(['kind' => 'text', 'text' => 'a;b'], $this->extract("\u{FEFF}a;b", 'text/csv', 'utf-8'));
		$this->assertSame('{"a":1}', $this->extract('{"a":1}', 'application/json', null)['text']);
		$this->assertSame('ok', $this->extract('ok', 'text/plain', 'x-unknown-charset')['text']);
	}

	/**
	 * HTML, declared or merely starting as a document under a text type, is turned into text, scripts and remote
	 * references included, never returned as markup.
	 *
	 * @return void
	 */
	public function testHtmlIsNeverReturnedAsMarkup(): void {
		$html = '<html><head><script>steal()</script></head><body><p>Hello</p><img src="https://tracker.example/p.gif"></body></html>';
		foreach (['text/html', 'text/plain'] as $type) {
			$result = $this->extract($html, $type, 'utf-8');
			$this->assertSame('html', $result['kind'], $type);
			$this->assertSame('Hello', $result['text'], $type);
		}
	}

	/**
	 * Content that is not text, or text under a type outside the list, is refused before any parser runs.
	 *
	 * @return void
	 */
	public function testOtherKindsAreRefused(): void {
		$this->assertRefused(MailAttachmentMessages::unsupportedType(), "GIF89a\x01\x00", 'image/gif');
		$this->assertRefused(MailAttachmentMessages::unsupportedType(), 'plain words', 'application/octet-stream');
		$this->assertRefused(MailAttachmentMessages::unsupportedType(), "binary\0data", 'text/plain');
		$this->assertRefused(MailAttachmentMessages::unsupportedType(), "\xD0\xCF\x11\xE0 legacy office", 'application/msword');
		$this->assertRefused(MailAttachmentMessages::unsupportedType(), 'a PDF is declared but this is text', 'application/pdf');
	}

	/**
	 * A PDF is recognized by its content, whatever its declared type, and its text is read, compressed or not.
	 *
	 * @return void
	 */
	public function testPdfTextIsReadWhateverTheDeclaredType(): void {
		foreach (['application/pdf', 'application/octet-stream', 'text/plain'] as $type) {
			$result = $this->extract(AttachmentSamples::pdf('Quarterly report'), $type, null);
			$this->assertSame('pdf', $result['kind'], $type);
			$this->assertStringContainsString('Quarterly report', $result['text'], $type);
		}
		$this->assertStringContainsString('Packed text', $this->extract(AttachmentSamples::pdf('Packed text', 'FlateDecode'), 'application/pdf', null)['text']);
	}

	/**
	 * A PDF whose decoding has no bound in the parser, or that is encrypted, is refused from its raw bytes: an unbounded
	 * filter (also escaped), a filter from an indirect object, too many streams.
	 *
	 * @return void
	 */
	public function testPdfOutsideTheDecodingBudgetIsRefusedBeforeParsing(): void {
		$this->assertRefused(MailAttachmentMessages::encrypted(), AttachmentSamples::pdf('x', '', '/Encrypt 6 0 R', '<< /Filter /Standard >>'), 'application/pdf');
		$this->assertRefused(MailAttachmentMessages::unreadable(), AttachmentSamples::pdf('x', '', '', "<< /Filter /LZWDecode /Length 1 >>\nstream\nx\nendstream"), 'application/pdf');
		$this->assertRefused(MailAttachmentMessages::unreadable(), AttachmentSamples::pdf('x', '', '', "<< /Filter /RunLength#44ecode /Length 1 >>\nstream\nx\nendstream"), 'application/pdf');
		$this->assertRefused(MailAttachmentMessages::unreadable(), AttachmentSamples::pdf('x', '', '', "<< /Filter 7 0 R /Length 1 >>\nstream\nx\nendstream"), 'application/pdf');
		$many = str_repeat("<< /Length 1 >>\nstream\nx\nendstream\n", MailPdfPreflight::MAX_STREAMS);
		$this->assertRefused(MailAttachmentMessages::unreadable(), AttachmentSamples::pdf('x', '', '', $many), 'application/pdf');
	}

	/**
	 * The stream count stops exactly at the limit: endstream keywords do not count, and a file at the limit is parsed.
	 *
	 * @return void
	 */
	public function testThePdfStreamLimitIsExact(): void {
		$atLimit = str_repeat("<< /Length 1 >>\nstream\nx\nendstream\n", MailPdfPreflight::MAX_STREAMS - 1);
		MailPdfPreflight::check(AttachmentSamples::pdf('x', '', '', $atLimit));
		$this->addToAssertionCount(1);
	}

	/**
	 * A damaged PDF is refused with the safe message, never the parser's.
	 *
	 * @return void
	 */
	public function testADamagedPdfIsRefused(): void {
		$this->assertRefused(MailAttachmentMessages::unreadable(), "%PDF-1.4\nnot really a pdf", 'application/pdf');
	}

	/**
	 * DOCX and ODT are recognized by the entries they hold and read with paragraph breaks.
	 *
	 * @return void
	 */
	public function testDocxAndOdtAreReadFromTheirArchive(): void {
		$this->assertSame(['kind' => 'docx', 'text' => "Budget\nApproved"], $this->extract(AttachmentSamples::docx('Budget', 'Approved'), 'application/octet-stream', null));
		$this->assertSame(['kind' => 'odt', 'text' => 'Minutes & notes'], $this->extract(AttachmentSamples::odt('Minutes &amp; notes'), 'application/zip', null));
	}

	/**
	 * Another archive, an encrypted document and an archive over the limits are refused before anything is inflated.
	 *
	 * @return void
	 */
	public function testArchivesOutsideTheRulesAreRefused(): void {
		$this->assertRefused(MailAttachmentMessages::unsupportedType(), AttachmentSamples::zip(['xl/workbook.xml' => '<w/>']), 'application/zip');
		$this->assertRefused(MailAttachmentMessages::encrypted(), AttachmentSamples::odt('secret', true), 'application/vnd.oasis.opendocument.text');
		$this->assertRefused(MailAttachmentMessages::encrypted(), AttachmentSamples::zip(['[Content_Types].xml' => '<T/>', 'word/document.xml' => '<w:p/>'], 'pw'), 'application/zip');
		$big = str_repeat('<w:p>a</w:p>', intdiv(MailAttachmentDocument::MAX_XML_BYTES, 12) + 1);
		$this->assertRefused(MailAttachmentMessages::unreadable(), AttachmentSamples::zip(['[Content_Types].xml' => '<T/>', 'word/document.xml' => $big]), 'application/zip');
		$entries = [];
		for ($i = 0; $i <= MailAttachmentDocument::MAX_ENTRIES; $i++) {
			$entries['e' . $i] = '';
		}
		$this->assertRefused(MailAttachmentMessages::unreadable(), AttachmentSamples::zip($entries), 'application/zip');
		$this->assertRefused(MailAttachmentMessages::unreadable(), "PK\x03\x04 broken archive", 'application/zip');
	}

	/**
	 * The extraction of one content.
	 *
	 * @param string $bytes decoded attachment
	 * @param string $type declared MIME type
	 * @param string|null $charset declared charset
	 * @return array{kind:string, text:string} the result
	 */
	private function extract(string $bytes, string $type, ?string $charset): array {
		return $this->text()->extract($bytes, new MailAttachmentPart('2', $type, $charset, 'file'));
	}

	/**
	 * Asserts the extraction is refused with exactly that message.
	 *
	 * @param string $message expected failure
	 * @param string $bytes decoded attachment
	 * @param string $type declared MIME type
	 * @return void
	 */
	private function assertRefused(string $message, string $bytes, string $type): void {
		try {
			$this->extract($bytes, $type, null);
			$this->fail('refusal expected: ' . $message);
		} catch (ToolFailure $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}

	/**
	 * The extractor over a temporary file manager that hands out real temporary files.
	 *
	 * @return MailAttachmentText the extractor
	 */
	private function text(): MailAttachmentText {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(function (): string {
			return $this->temporary[] = (string)tempnam(sys_get_temp_dir(), 'mcp-att');
		});
		return new MailAttachmentText(new MailAttachmentDocument($temp));
	}
}
