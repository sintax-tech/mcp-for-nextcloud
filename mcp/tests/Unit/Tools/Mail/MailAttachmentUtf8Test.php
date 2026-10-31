<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples;
use OCA\Mcp\Tools\Mail\MailAttachmentDocument;
use OCA\Mcp\Tools\Mail\MailAttachmentPart;
use OCA\Mcp\Tools\Mail\MailAttachmentText;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Issue #25 on mail attachments: PDF, DOCX and ODT attachments whose parser output is not valid UTF-8 come back as
 * valid UTF-8 with their characters, instead of bytes that only the response's substitution would hide.
 */
final class MailAttachmentUtf8Test extends TestCase {
	/**
	 * A PDF attachment reads with the character its surrogate pair encodes, as valid UTF-8.
	 *
	 * @return void
	 */
	public function testPdfAttachmentComesBackAsValidUtf8(): void {
		$this->assertSame(['kind' => 'pdf', 'text' => BrokenTextSamples::PDF_TEXT], $this->extract(BrokenTextSamples::pdf()));
	}

	/**
	 * DOCX and ODT attachments whose XML declares Windows-1252 and holds entities read with every letter, as valid UTF-8.
	 *
	 * @return void
	 */
	#[RequiresPhpExtension('zip')]
	public function testSingleByteDocumentAttachmentsComeBackAsValidUtf8(): void {
		$latin = BrokenTextSamples::LATIN_TEXT;
		$this->assertSame(['kind' => 'docx', 'text' => $latin . "\n" . $latin], $this->extract(BrokenTextSamples::docx()));
		$this->assertSame(['kind' => 'odt', 'text' => $latin], $this->extract(BrokenTextSamples::odt()));
	}

	/**
	 * The text of an attachment.
	 *
	 * @param string $bytes the decoded attachment
	 * @return array{kind:string, text:string} the result
	 */
	private function extract(string $bytes): array {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => (string)tempnam(sys_get_temp_dir(), 'mcp-att'));
		$text = new MailAttachmentText(new MailAttachmentDocument($temp));
		return $text->extract($bytes, new MailAttachmentPart('2', 'application/octet-stream', null, 'file'));
	}
}
