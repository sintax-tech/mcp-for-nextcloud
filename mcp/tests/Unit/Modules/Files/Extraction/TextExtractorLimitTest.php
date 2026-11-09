<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Extraction;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;
use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Files\File;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/** The file ceiling follows the administrator; a zip entry stays at 20 MiB. */
final class TextExtractorLimitTest extends TestCase {
	private string $previousMemory;

	protected function setUp(): void {
		$this->previousMemory = (string)ini_get('memory_limit');
		$this->assertNotFalse(ini_set('memory_limit', '-1'));
	}

	protected function tearDown(): void {
		ini_set('memory_limit', $this->previousMemory);
	}

	private function extractor(?int $mib): TextExtractor {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
		$stored = new InMemoryConfig();
		if ($mib !== null) {
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = (string)($mib * 1024 * 1024);
		}
		return new TextExtractor($temp, new FileReadLimit($stored->mock($this)));
	}

	/** @param int $size size the node reports, in bytes */
	private function file(int $size, string $name = 'note.txt', string $body = "hello\n", string $mime = 'text/plain'): File {
		$stream = fopen('php://temp', 'w+');
		fwrite($stream, $body);
		rewind($stream);
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn($size);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('fopen')->willReturn($stream);
		return $file;
	}

	public function testWithoutALimitTheCeilingStaysTwentyMib(): void {
		$extractor = $this->extractor(null);
		$this->assertSame(TextExtractor::MAX_BYTES, $extractor->maxFileBytes());
		$this->assertSame(20 * 1024 * 1024, TextExtractor::MAX_BYTES);
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(TextExtractor::tooLarge(TextExtractor::MAX_BYTES));
		$extractor->readText($this->file(TextExtractor::MAX_BYTES + 1));
	}

	public function testARaisedLimitReadsAFileTheOldConstantRefused(): void {
		$extractor = $this->extractor(40);
		$this->assertSame(40 * 1024 * 1024, $extractor->maxFileBytes());
		$this->assertSame("hello\n", $extractor->readText($this->file(TextExtractor::MAX_BYTES + 1)));
	}

	public function testAFileAboveTheConfiguredLimitIsRefused(): void {
		$extractor = $this->extractor(40);
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(TextExtractor::tooLarge(40 * 1024 * 1024));
		$extractor->readText($this->file(40 * 1024 * 1024 + 1));
	}

	public function testAZipEntryStaysCappedWhenTheFileLimitIsHigher(): void {
		$extractor = $this->extractor(40);
		$docx = self::zip('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:t>HELLO_MCP</w:t></w:p></w:document>');
		$this->assertStringContainsString('HELLO_MCP', $extractor->extractBytes($docx, 'note.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		$bomb = self::zip('word/document.xml', str_repeat('A', FileReadLimit::ZIP_ENTRY_MAX_BYTES + 1));
		$this->assertSame(ReadingMessages::notExtracted('bomb.docx'), $extractor->extractBytes($bomb, 'bomb.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		$this->assertSame(20 * 1024 * 1024, FileReadLimit::ZIP_ENTRY_MAX_BYTES);
	}

	/** 128M used to shrink the default to 16 MiB. The default, and a file between 16 and 20 MiB, stay readable. */
	public function testASmallHostStillReadsTheTwentyMibDefault(): void {
		$this->assertNotFalse(ini_set('memory_limit', '128M'));
		$extractor = $this->extractor(null);
		$this->assertSame(20 * 1024 * 1024, $extractor->maxFileBytes());
		$this->assertSame(80 * 1024 * 1024, $extractor->maxFileBytes() * 4);
		$this->assertSame("hello\n", $extractor->readText($this->file(17 * 1024 * 1024)));
	}

	/** readText of a PDF asks for the stored ceiling once and passes that same value into the decode allowance. */
	public function testOnePdfReadResolvesTheCeilingOnce(): void {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->expects($this->once())->method('getAppValue')
			->with('mcp', FileReadLimit::MAX_BYTES_KEY, (string)FileReadLimit::DEFAULT_MAX_BYTES)
			->willReturn((string)FileReadLimit::DEFAULT_MAX_BYTES);
		$temp = $this->createMock(ITempManager::class);
		$extractor = new TextExtractor($temp, new FileReadLimit($config));
		$pdf = \OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples::pdf();
		$text = $extractor->readText($this->file(strlen($pdf), 'a.pdf', $pdf, 'application/pdf'));
		$this->assertNotSame('', $text);
	}

	private static function zip(string $entry, string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'mcpzip');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString($entry, $content);
		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}
}
