<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples;
use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Issue #25: files_read (and files_version_read and resources/read, which share {@see TextExtractor::extractBytes()})
 * return valid UTF-8 for documents whose parser output is not, so the response encodes without a substitution flag
 * and keeps the characters that can be read back.
 */
final class FilesReadUtf8Test extends FilesToolsTestCase {
	/**
	 * A PDF whose ToUnicode map holds a surrogate pair reads with the character, through files_read.
	 *
	 * @return void
	 */
	public function testPdfWithASurrogatePairReadsThroughFilesRead(): void {
		$this->tree->addFile('/alice/files/Documentos/emoji.pdf', BrokenTextSamples::pdf(), 'application/pdf');
		$result = $this->tool('files_read', ['path' => '/Documentos/emoji.pdf']);
		$this->assertSame(BrokenTextSamples::PDF_TEXT, $result['content'][0]['text']);
		json_encode($result, JSON_THROW_ON_ERROR);
	}

	/**
	 * A PDF given as bytes (a stored version) comes back as valid UTF-8 with its characters.
	 *
	 * @return void
	 */
	public function testPdfBytesComeBackAsValidUtf8(): void {
		$extractor = new TextExtractor($this->temp, new FileReadLimit($this->config->mock($this)));
		$this->assertSame(BrokenTextSamples::PDF_TEXT, $extractor->extractBytes(BrokenTextSamples::pdf(), 'v.pdf', 'application/pdf'));
	}

	/**
	 * DOCX and ODT whose XML declares Windows-1252 and holds entities come back as valid UTF-8 with every letter.
	 *
	 * @return void
	 */
	#[RequiresPhpExtension('zip')]
	public function testSingleByteDocumentsComeBackAsValidUtf8(): void {
		$extractor = new TextExtractor($this->temp, new FileReadLimit($this->config->mock($this)));
		$latin = BrokenTextSamples::LATIN_TEXT;
		$this->assertSame($latin . "\n" . $latin, $extractor->extractBytes(BrokenTextSamples::docx(), 'v.docx', ''));
		$this->assertSame($latin, $extractor->extractBytes(BrokenTextSamples::odt(), 'v.odt', ''));
	}
}
