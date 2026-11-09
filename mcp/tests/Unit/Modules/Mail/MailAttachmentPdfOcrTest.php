<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Extraction\MailAttachmentPdf;
use PHPUnit\Framework\TestCase;

/** A scan made searchable by ocrmypdf and sent as a mail attachment gives its text layer, as files_read does (#31). */
final class MailAttachmentPdfOcrTest extends TestCase {
	/**
	 * The page stored in an object stream keeps its `/OCR-…` Form XObject, and the text inside it is returned.
	 *
	 * @return void
	 */
	public function testTheTextLayerOfAnOcrmypdfScanIsReturned(): void {
		$bytes = (string)file_get_contents(__DIR__ . '/../../../fixtures/pdf-ocr/ocrmypdf15-tesseract5.pdf');
		$this->assertSame('Scanned sample text 4711', trim(MailAttachmentPdf::text($bytes)));
	}
}
