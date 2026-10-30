<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailAttachmentMessages;
use OCA\Mcp\Tools\Mail\MailAttachmentPdf;
use OCA\Mcp\Tools\Mail\MailPdfFilters;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The PDF decoders of an attachment hold their budget and tell when it was hit: a small compressed page whose content
 * decodes past 256 KiB is refused, never returned without the text after the cut, while the same page below the budget
 * reads whole; so does a damaged stream without its checksum, the case where the library's own fallback returns the
 * first 256 KiB as if they were all; a compressed bomb stops right past the budget; the unbounded decoders never run.
 */
final class MailPdfFiltersTest extends TestCase {
	/**
	 * Past the budget the PDF is refused with the fixed message; its compressed form is far below 1 MiB.
	 *
	 * @return void
	 */
	public function testAStreamPastTheBudgetRefusesTheDocument(): void {
		foreach ([false, true] as $damaged) {
			$pdf = self::paddedPage(MailAttachmentPdf::STREAM_BYTES + 4096, $damaged);
			$this->assertLessThan(16384, strlen($pdf), 'a small file');
			try {
				MailAttachmentPdf::text($pdf);
				$this->fail('refusal expected' . ($damaged ? ' (damaged stream)' : ''));
			} catch (ToolFailure $e) {
				$this->assertSame(MailAttachmentMessages::pdfOverBudget(), $e->getMessage());
			}
		}
	}

	/**
	 * Below the budget the same page reads whole, its last words included, damaged stream or not.
	 *
	 * @return void
	 */
	public function testAStreamBelowTheBudgetReadsWhole(): void {
		foreach ([false, true] as $damaged) {
			$this->assertStringContainsString('Hidden tail', MailAttachmentPdf::text(self::paddedPage(MailAttachmentPdf::STREAM_BYTES - 4096, $damaged)));
		}
	}

	/**
	 * A compressed bomb (8 MiB of zeros in a few KiB) stops right past the budget, never inflated whole.
	 *
	 * @return void
	 */
	public function testABombStopsRightPastTheBudget(): void {
		$bomb = (string)gzcompress(str_repeat("\0", 8 * 1024 * 1024), 9);
		$this->assertLessThan(16384, strlen($bomb));
		$filters = new MailPdfFilters();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		try {
			$filters->decodeFilter('FlateDecode', $bomb, MailAttachmentPdf::STREAM_BYTES);
			$this->fail('overflow expected');
		} catch (\RuntimeException) {
			$this->assertTrue($filters->overBudget);
		}
		$this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before, 'far below the 8 MiB the bomb holds');
	}

	/**
	 * The decoder stops past the limit and marks it; a stream within the limit decodes whole and marks nothing.
	 *
	 * @return void
	 */
	public function testFlateDecodesWholeOrMarksTheOverflow(): void {
		$filters = new MailPdfFilters();
		$this->assertSame(str_repeat('a', 1000), $filters->decodeFilter('FlateDecode', (string)gzcompress(str_repeat('a', 1000)), 1000));
		$this->assertFalse($filters->overBudget);
		try {
			$filters->decodeFilter('FlateDecode', (string)gzcompress(str_repeat('a', 1001)), 1000);
			$this->fail('overflow expected');
		} catch (\RuntimeException) {
			$this->assertTrue($filters->overBudget);
		}
	}

	/**
	 * LZWDecode, RunLengthDecode and a FlateDecode without budget never decode; each marks the document.
	 *
	 * @return void
	 */
	public function testUnboundedDecodersNeverRun(): void {
		foreach ([['LZWDecode', "\x80\x0b\x60\x50\x22\x0c\x0c\x85\x01", 0], ['RunLengthDecode', "\x81a\x80", 0], ['FlateDecode', (string)gzcompress('a'), 0]] as [$filter, $data, $limit]) {
			$filters = new MailPdfFilters();
			try {
				$filters->decodeFilter($filter, $data, $limit);
				$this->fail("$filter must not decode");
			} catch (\RuntimeException) {
				$this->assertTrue($filters->unbounded, $filter);
			}
		}
	}

	/**
	 * A compressed page whose content stream decodes to about that many bytes, with its only text at the very end.
	 *
	 * @param int $bytes decoded length before the text
	 * @param bool $damaged whether the compressed stream lacks its final checksum
	 * @return string the PDF
	 */
	private static function paddedPage(int $bytes, bool $damaged = false): string {
		return AttachmentSamples::pdfWithContent('BT /F1 18 Tf 20 100 Td ' . str_repeat(' ', $bytes) . '(Hidden tail) Tj ET', true, '', '', $damaged);
	}
}
