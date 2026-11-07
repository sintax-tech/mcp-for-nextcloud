<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCA\Mcp\Modules\Logs\Reading\LogFilter;
use OCA\Mcp\Modules\Logs\Reading\LogScanner;
use OCA\Mcp\Modules\Logs\Reading\LogTime;
use OCA\Mcp\Modules\Logs\Reading\ReverseLogFile;
use PHPUnit\Framework\TestCase;

/** Large synthetic logs exercise the real block reader, official core parity, deadlines and resumable offsets. */
final class TimedLogScanTest extends TestCase {
	/** @param array<string,mixed> $args Tool filters. @return LogFilter Validated filter. */
	private function filter(array $args): LogFilter {
		return LogFilter::fromArguments($args, new LogTime(DATE_ATOM, 'UTC'));
	}

	/** @return void Large JSON entries and low-level lines yield exactly the official eligible-entry offsets. */
	public function testLargeEntriesMatchTheOfficialReaderAtEligibleOffsets(): void {
		$fixture = new FakeFileLog(FakeFileLog::lines(60, static fn (int $i): array => [
			'level' => $i % 5, 'app' => $i % 2 ? 'other' : 'wanted', 'message' => str_repeat('synthetic ', 450),
			'exception' => ['Trace' => array_fill(0, 12, ['function' => 'synthetic', 'args' => [str_repeat('x', 220)]])],
		]));
		$core = new OfficialFileLog($fixture->getLogFilePath(), 2);
		$scanner = new LogScanner(FileLogFixture::reader($this, $core, 2));
		foreach ([0, 7, 23] as $offset) {
			$filter = $this->filter(['min_level' => 3, 'app' => 'wanted']);
			$native = $core->getEntries(5000, $offset);
			$expected = array_values(array_filter($native, static fn ($entry): bool => $filter->test($entry) === LogFilter::MATCH));
			$page = $scanner->scan($filter, $offset, 3);
			self::assertEquals(array_slice($expected, 0, 3), $page['entries']);
			if ($page['nextOffset'] !== null) {
				$rest = $scanner->scan($filter, $page['nextOffset'], 100);
				self::assertEquals($expected, [...$page['entries'], ...$rest['entries']]);
			}
			self::assertFalse($page['timeBudgetReached']);
		}
	}

	/** @return void A forced time pause preserves every match and the continuation consumes exactly the unread suffix. */
	public function testTimeBudgetReturnsPartialResultsAndAnHonestContinuation(): void {
		$fixture = new FakeFileLog(FakeFileLog::lines(3000, static fn (int $i): array => [
			'level' => $i % 5, 'app' => $i % 37 === 0 ? 'wanted' : 'other', 'message' => str_repeat('synthetic ', 700),
		]));
		$clock = 0.0;
		$advance = true;
		$tick = static function () use (&$clock, &$advance): float { return $advance ? $clock += 0.01 : $clock; };
		$scanner = new LogScanner(FileLogFixture::reader($this, $fixture), $tick);
		$filter = $this->filter(['app' => 'wanted']);
		$started = hrtime(true);
		$first = $scanner->scan($filter, 0, 100);
		self::assertTrue($first['timeBudgetReached']);
		self::assertTrue($first['truncated']);
		self::assertFalse($first['budgetReached']);
		self::assertGreaterThan(0, $first['nextOffset']);
		$advance = false;
		$rest = $scanner->scan($filter, $first['nextOffset'], 100);
		$expected = array_reverse(array_values(array_filter(array_map('json_decode', $fixture->lines), static fn ($e): bool => $e->app === 'wanted')));
		self::assertEquals($expected, [...$first['entries'], ...$rest['entries']]);
		self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9, 'Multi-megabyte synthetic pages must not use bytewise core traversal');
	}

	/** @return void Expiration inside a block-spanning line or during offset seeking never consumes an unread entry. */
	public function testTimePauseDuringSeekingOrAnIncompleteLineDoesNotAdvanceTheOffset(): void {
		$fixture = new FakeFileLog(FakeFileLog::lines(10, static fn (int $i): array => ['message' => str_repeat('synthetic ', 20000)]));
		foreach ([0, 4] as $offset) {
			$clock = 0.0;
			$advance = true;
			$tick = static function () use (&$clock, &$advance): float { return $advance ? $clock += 0.25 : $clock; };
			$scanner = new LogScanner(FileLogFixture::reader($this, $fixture), $tick);
			$paused = $scanner->scan($this->filter([]), $offset, 1);
			self::assertTrue($paused['timeBudgetReached']);
			self::assertSame([], $paused['entries']);
			self::assertSame($offset, $paused['nextOffset']);
			self::assertSame(0, $paused['scanned']);
			$advance = false;
			$resumed = $scanner->scan($this->filter([]), $paused['nextOffset'], 1);
			self::assertSame('req' . (9 - $offset), $resumed['entries'][0]->reqId);
		}
	}

	/** @return void A line above 16 MiB is never decoded, occupies an explicit unparsed offset and cannot hide its neighbors. */
	public function testOversizedLineIsReportedWithoutDroppingNeighboringEntries(): void {
		$this->assertOversizedLine(3 * ReverseLogFile::MAX_LINE_BYTES);
	}

	/** @return void The first partial block above the cap is also rejected without decoding. */
	public function testLineJustAboveTheCapIsReportedAsOversized(): void {
		$this->assertOversizedLine(ReverseLogFile::MAX_LINE_BYTES + 1);
	}

	/** @param int $bytes Exact synthetic line length. @return void Assert bounded allocation and resumable neighbors. */
	private function assertOversizedLine(int $bytes): void {
		$path = tempnam(sys_get_temp_dir(), 'mcp-it-oversized-');
		try {
			$stream = fopen($path, 'wb');
			fwrite($stream, "{\"level\":2,\"message\":\"older\"}\n{\"level\":2,\"message\":\"");
			$remaining = $bytes - strlen('{"level":2,"message":""}');
			while ($remaining > 0) {
				$chunk = min($remaining, ReverseLogFile::BLOCK_BYTES);
				fwrite($stream, str_repeat('x', $chunk));
				$remaining -= $chunk;
			}
			fwrite($stream, "\"}\n{\"level\":2,\"message\":\"newer\"}\n");
			fclose($stream);
			$core = new OfficialFileLog($path, 2);
			$scanner = new LogScanner(FileLogFixture::reader($this, $core, 2));
			memory_reset_peak_usage();
			$memory = memory_get_usage();
			$first = $scanner->scan($this->filter([]), 0, 1);
			$rest = $scanner->scan($this->filter([]), $first['nextOffset'], 10);
			self::assertSame(['newer', 'older'], array_column([...$first['entries'], ...$rest['entries']], 'message'));
			self::assertSame(1, $rest['unparsed']);
			self::assertSame('oversized', $rest['oversized'][0]['reason']);
			self::assertSame(1, $rest['oversized'][0]['offset']);
			self::assertSame($bytes, $rest['oversized'][0]['bytes']);
			self::assertSame(2, $rest['scanned']);
			self::assertNull($rest['nextOffset']);
			self::assertLessThan(ReverseLogFile::MAX_LINE_BYTES + 24 * ReverseLogFile::BLOCK_BYTES,
				memory_get_peak_usage() - $memory, 'Oversized buffering must not allocate a second line-sized string');
			$resumed = $scanner->scan($this->filter([]), 2, 1);
			self::assertSame(['older'], array_column($resumed['entries'], 'message'));
		} finally { unlink($path); }
	}
}
