<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Modules\Files\Editing\ReplaceSource;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;

/**
 * files_replace refuses a file over the read ceiling, or with no usable size, before getContent(), on the plan
 * and on the confirmed write. A file at the ceiling and a normal file keep the replacement. A refusal writes nothing.
 */
final class ReplaceSourceTest extends FilesToolsTestCase {
	private const ABS = '/alice/files/Documentos/teto.txt';
	private const REL = '/Documentos/teto.txt';

	/** A reported size already over the ceiling never opens the file. */
	public function testAKnownOversizedFileIsRefusedBeforeGetContent(): void {
		$file = $this->sized(TextExtractor::MAX_BYTES + 1);
		$file->expects($this->never())->method('getContent');
		$file->expects($this->never())->method('fopen');
		$this->assertTooLarge(fn () => ReplaceSource::read($file));
	}

	/** The same refusal for a non-finite size: there is no length to trust, and nothing is read. */
	public function testANonFiniteSizeIsRefusedBeforeGetContent(): void {
		foreach ([INF, NAN] as $size) {
			$file = $this->sized($size);
			$file->expects($this->never())->method('getContent');
			$file->expects($this->never())->method('fopen');
			$this->assertTooLarge(fn () => ReplaceSource::read($file));
		}
	}

	/** A size that is not a number is not a length, so the file is not read. */
	public function testANonNumericSizeIsRefusedBeforeGetContent(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(null);
		$file->expects($this->never())->method('getContent');
		$file->expects($this->never())->method('fopen');
		$this->assertTooLarge(fn () => ReplaceSource::read($file));
	}

	/** The ceiling itself is still readable, through getContent(), and every byte comes back. */
	public function testAFileExactlyAtTheCeilingIsRead(): void {
		$bytes = $this->atCeiling('UNIQ');
		$file = $this->sized(TextExtractor::MAX_BYTES);
		$file->expects($this->once())->method('getContent')->willReturn($bytes);
		$file->expects($this->never())->method('fopen');
		$this->assertSame($bytes, ReplaceSource::read($file));
	}

	/** A normal file is read with getContent() and returned as stored. */
	public function testANormalFileIsReadWithGetContent(): void {
		$file = $this->sized(11);
		$file->expects($this->once())->method('getContent')->willReturn("hello UNIQ\n");
		$this->assertSame("hello UNIQ\n", ReplaceSource::read($file));
	}

	/**
	 * A storage can report a small size and then hand back more. getContent() has already run, so this is not a
	 * ceiling on the read; the bytes are still refused and the caller does not keep them.
	 */
	public function testALyingSizeIsRefusedAfterGetContent(): void {
		$file = $this->sized(4);
		$file->expects($this->once())->method('getContent')->willReturn(str_repeat('x', TextExtractor::MAX_BYTES + 1));
		$file->expects($this->never())->method('fopen');
		$this->assertTooLarge(fn () => ReplaceSource::read($file));
	}

	/**
	 * Negative sizes are the Nextcloud sentinels for a size that was not computed, is unknown, or is unlimited.
	 * None of them is a length, so nothing is read.
	 */
	public function testANegativeSizeIsRefusedBeforeGetContent(): void {
		foreach ([-1, -2, -3] as $size) {
			$file = $this->sized($size);
			$file->expects($this->never())->method('getContent');
			$file->expects($this->never())->method('fopen');
			$this->assertTooLarge(fn () => ReplaceSource::read($file));
		}
	}

	/** The plan of an oversized file refuses before getContent() and writes nothing. */
	public function testThePlanRefusesAnOversizedFileBeforeReadingIt(): void {
		$this->oversized();
		$this->assertSame(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES), $this->planFailure('files_replace', $this->args()));
		$this->assertNeverReadOrWritten();
	}

	/** The confirmed write refuses the same file on its own, also before getContent(). */
	public function testTheConfirmedWriteRefusesAnOversizedFileBeforeReadingIt(): void {
		$this->oversized();
		$this->assertSame(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES), $this->failure('files_replace', $this->args()));
		$this->assertNeverReadOrWritten();
	}

	/** A stale ETag is still refused on the plan before the file is read, oversized or not. */
	public function testAStaleEtagOnThePlanIsRefusedBeforeTheSizeRead(): void {
		$this->oversized();
		$this->assertSame(CommonMessages::conflict(), $this->planFailure('files_replace', $this->args() + ['etag' => 'outro']));
		$this->assertNeverReadOrWritten();
	}

	/** A shared oversized file still asks for confirmation, and that ask does not read the bytes. */
	public function testASharedOversizedFileAsksBeforeReading(): void {
		$this->tree->addFile(self::ABS, "hello UNIQ\n", 'text/plain', ['scope' => 'shared', 'size' => TextExtractor::MAX_BYTES + 1]);
		$out = $this->json('files_replace', $this->args());
		$this->assertTrue($out['requiresConfirmation']);
		$this->assertSame("hello UNIQ\n", $this->tree->nodes[self::ABS]['content']);
		$this->assertSame([], $this->tree->contentReads);
		$this->assertSame([], $this->tree->ops);
	}

	/** Once the shared write is confirmed, the ceiling still refuses it and nothing is written. */
	public function testAConfirmedSharedOversizedFileIsRefusedWithoutWriting(): void {
		$this->tree->addFile(self::ABS, "hello UNIQ\n", 'text/plain', ['scope' => 'shared', 'size' => TextExtractor::MAX_BYTES + 1]);
		$this->assertSame(
			ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES),
			$this->failure('files_replace', $this->args() + ['confirm_shared' => true]),
		);
		$this->assertSame("hello UNIQ\n", $this->tree->nodes[self::ABS]['content']);
		$this->assertSame([], $this->tree->contentReads);
		$this->assertSame([], $this->tree->ops);
	}

	/** A file that fits at plan time and grows past the ceiling before confirm is refused then, unread and unwritten. */
	public function testGrowthPastTheCeilingBetweenPlanAndConfirmIsRefused(): void {
		$this->tree->addFile(self::ABS, "hello UNIQ\n", 'text/plain');
		$plan = $this->plan('files_replace', $this->args());
		$this->assertSame(1, $plan['snippet']['occurrences']);
		$this->assertNotSame([], $this->tree->contentReads);
		$this->tree->nodes[self::ABS]['size'] = TextExtractor::MAX_BYTES + 1;
		$this->tree->contentReads = [];
		$this->tree->opened = [];
		$this->tree->ops = [];
		$this->assertSame(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES), $this->failure('files_replace', $this->args()));
		$this->assertSame("hello UNIQ\n", $this->tree->nodes[self::ABS]['content']);
		$this->assertNeverReadOrWritten();
	}

	/** A file of exactly the ceiling is planned and written. getContent() does run, because the size allows it. */
	public function testAFileExactlyAtTheCeilingIsReplaced(): void {
		$marker = 'UNIQ';
		$content = $this->atCeiling($marker);
		$this->tree->addFile(self::ABS, $content, 'text/plain');
		$plan = $this->plan('files_replace', $this->args());
		$this->assertSame(TextExtractor::MAX_BYTES, $plan['size']['before']);
		$this->assertSame(TextExtractor::MAX_BYTES, $plan['size']['after']);
		$this->assertCount(1, $this->tree->contentReads);
		$out = $this->json('files_replace', $this->args());
		$this->assertSame(str_replace($marker, 'NOVO', $content), $this->tree->nodes[self::ABS]['content']);
		$this->assertStringStartsWith('/MCP backups/Documentos/teto.txt.', $out['backup']);
		$this->assertGreaterThanOrEqual(2, count($this->tree->contentReads));
	}

	/** A normal snippet replacement still reads with getContent() and writes the one change. */
	public function testANormalReplaceStillReadsAndWrites(): void {
		$this->tree->addFile(self::ABS, "hello UNIQ\n", 'text/plain');
		$out = $this->json('files_replace', $this->args());
		$this->assertSame("hello NOVO\n", $this->tree->nodes[self::ABS]['content']);
		$this->assertSame([self::ABS], $this->tree->contentReads);
		$this->assertStringContainsString('NOVO', $out['diff']);
	}

	/** A negative reported size is refused on the plan and on the confirmed write, with no read and no write. */
	public function testANegativeSizeIsRefusedOnThePlanAndOnConfirm(): void {
		foreach ([-1, -2, -3] as $size) {
			$this->tree->addFile(self::ABS, "hello UNIQ\n", 'text/plain', ['size' => $size]);
			$this->tree->contentReads = [];
			$this->tree->opened = [];
			$this->tree->ops = [];
			$this->assertSame(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES), $this->planFailure('files_replace', $this->args()));
			$this->assertSame(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES), $this->failure('files_replace', $this->args()));
			$this->assertNeverReadOrWritten();
		}
	}

	/**
	 * @param int|float $size reported size
	 */
	private function sized(int|float $size): File {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn($size);
		return $file;
	}

	/** @param callable():string $read */
	private function assertTooLarge(callable $read): void {
		try {
			$read();
		} catch (ToolFailure $e) {
			$this->assertSame(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES), $e->getMessage());
			return;
		}
		$this->fail('the read was accepted');
	}

	private function oversized(): void {
		$this->tree->addFile(self::ABS, "hello UNIQ\n", 'text/plain', ['size' => TextExtractor::MAX_BYTES + 1]);
	}

	/** @return array{path:string, old:string, new:string} */
	private function args(): array {
		return ['path' => self::REL, 'old' => 'UNIQ', 'new' => 'NOVO'];
	}

	private function assertNeverReadOrWritten(): void {
		$this->assertSame([], $this->tree->contentReads);
		$this->assertSame([], $this->tree->opened);
		$this->assertSame([], $this->tree->ops);
		$this->assertSame("hello UNIQ\n", $this->tree->nodes[self::ABS]['content']);
	}

	/** @return string MAX_BYTES of text ending in a unique marker, so the replacement stays at the ceiling */
	private function atCeiling(string $marker): string {
		return str_repeat('x', TextExtractor::MAX_BYTES - strlen($marker)) . $marker;
	}

	/** @return string the message of the ToolFailure the plan raised */
	private function planFailure(string $name, array $arguments): string {
		try {
			$this->plan($name, $arguments);
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail("the plan of $name did not fail");
	}
}
