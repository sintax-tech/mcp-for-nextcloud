<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Extraction\MailAttachmentPdf;
use OCA\Mcp\Modules\Mail\Extraction\MailPdfPreflight;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The raw PDF check reads names, white space and comments as smalot/pdfparser does: NUL ends a name and separates
 * tokens, so an unbounded filter or an indirect /Filter written with NUL (alone, in an array, after a comment) is
 * refused before the parser runs, like the same written with spaces; ordinary filters pass.
 */
final class MailPdfPreflightTest extends TestCase {
	/**
	 * Unbounded filters are refused however they are delimited: NUL, arrays, comments, escapes.
	 *
	 * @return void
	 */
	public function testUnboundedFiltersAreRefusedWhateverDelimitsThem(): void {
		foreach ([
			"<< /Filter /RunLengthDecode\0/Length 1 >>",
			"<< /Filter /LZWDecode\0 /Length 1 >>",
			"<< /Filter [/FlateDecode /RunLengthDecode\0] /Length 1 >>",
			"<< /Filter [/LZWDecode\0/FlateDecode] /Length 1 >>",
			"<< /Filter\0/RunLengthDecode /Length 1 >>",
			"<< /Filter /RunLengthDecode%comment\n /Length 1 >>",
			'<< /Filter /RunLength#44ecode /Length 1 >>',
			'<< /Filter /L#5AWDecode /Length 1 >>',
		] as $dictionary) {
			$this->assertRefused(MailAttachmentMessages::unreadable(), self::withStream($dictionary), $dictionary);
		}
	}

	/**
	 * A /Filter whose value is not a name or an array of names comes from an indirect object, also behind NUL or a
	 * comment, and is refused.
	 *
	 * @return void
	 */
	public function testIndirectFiltersAreRefusedWhateverSeparatesThem(): void {
		foreach ([
			'<< /Filter 7 0 R /Length 1 >>',
			"<< /Filter\x007 0 R /Length 1 >>",
			"<< /Filter\0\0 7\x000\x00R /Length 1 >>",
			"<< /Filter % hidden\n 7 0 R /Length 1 >>",
			"<< /Filter\0%c\r\0 7 0 R /Length 1 >>",
			'<< /Filter << /X 1 >> /Length 1 >>',
		] as $dictionary) {
			$this->assertRefused(MailAttachmentMessages::unreadable(), self::withStream($dictionary), $dictionary);
		}
	}

	/**
	 * /Encrypt behind NUL is still encryption.
	 *
	 * @return void
	 */
	public function testEncryptionBehindNulIsRefused(): void {
		$this->assertRefused(MailAttachmentMessages::encrypted(), AttachmentSamples::pdf('x', '', "/Encrypt\x006 0 R"), 'encrypt');
	}

	/**
	 * Direct names and arrays, also behind NUL, and other keys that merely start with Filter pass the check.
	 *
	 * @return void
	 */
	public function testOrdinaryFiltersPass(): void {
		foreach ([
			'<< /Filter /FlateDecode /Length 1 >>',
			"<< /Filter\0/FlateDecode /Length 1 >>",
			"<< /Filter\0[/ASCII85Decode /FlateDecode] /Length 1 >>",
			'<< /Filter [/FlateDecode 7 0 R] /Length 1 >>',
			'<< /FilterX 7 0 R /Length 1 >>',
		] as $dictionary) {
			MailPdfPreflight::check(self::withStream($dictionary));
		}
		$this->addToAssertionCount(1);
	}

	/**
	 * The refusal comes from the raw check, before the parser: the whole text() refuses the same way.
	 *
	 * @return void
	 */
	public function testTheTextRefusesBeforeParsing(): void {
		try {
			MailAttachmentPdf::text(self::withStream("<< /Filter /RunLengthDecode\0/Length 1 >>"));
			$this->fail('refusal expected');
		} catch (ToolFailure $e) {
			$this->assertSame(MailAttachmentMessages::unreadable(), $e->getMessage());
			$this->assertNull($e->getPrevious(), 'refused by the raw check, not by a parser failure');
		}
	}

	/**
	 * A valid PDF with one more stream object of that dictionary.
	 *
	 * @param string $dictionary the stream dictionary
	 * @return string the PDF
	 */
	private static function withStream(string $dictionary): string {
		return AttachmentSamples::pdf('x', '', '', $dictionary . "\nstream\nx\nendstream");
	}

	/**
	 * Asserts the raw check refuses with exactly that message.
	 *
	 * @param string $message expected failure
	 * @param string $pdf the raw PDF
	 * @param string $case what is checked
	 * @return void
	 */
	private function assertRefused(string $message, string $pdf, string $case): void {
		try {
			MailPdfPreflight::check($pdf);
			$this->fail('refusal expected: ' . addcslashes($case, "\0..\37"));
		} catch (ToolFailure $e) {
			$this->assertSame($message, $e->getMessage(), addcslashes($case, "\0..\37"));
		}
	}
}
