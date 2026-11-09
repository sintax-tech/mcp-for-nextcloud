<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Extraction;

use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The file-read ceiling is the configured value, or lower when memory_limit cannot pay for a PDF. */
final class FileReadLimitTest extends TestCase {
	/** @return array<string, array{string, bool, int}> directive, whether it is unlimited, parsed bytes */
	public static function memoryLimits(): array {
		return [
			'minus one' => ['-1', true, 0],
			'empty' => ['', true, 0],
			'blank' => ['  ', true, 0],
			'megabytes' => ['128M', false, 128 * 1024 * 1024],
			'megabytes with B' => ['256MB', false, 256 * 1024 * 1024],
			'gigabytes' => ['1G', false, 1024 ** 3],
			'lowercase' => ['32m', false, 32 * 1024 * 1024],
			'spaced' => [' 128 M ', false, 128 * 1024 * 1024],
			'zero' => ['0', false, 0],
			'unparsable' => ['nope', false, 0],
			'other negative' => ['-2', false, 0],
		];
	}

	#[DataProvider('memoryLimits')]
	public function testMemoryLimitParsing(string $value, bool $unlimited, int $bytes): void {
		$this->assertSame($unlimited, FileReadLimit::isUnlimited($value));
		$this->assertSame($bytes, FileReadLimit::parseMemoryLimit($value));
	}

	public function testCeilingLeavesRoomForTheParserCopies(): void {
		$this->assertSame(16 * 1024 * 1024, FileReadLimit::ceilingBytes(128 * 1024 * 1024));
		$this->assertSame(173364565, FileReadLimit::ceilingBytes(1024 ** 3));
		$this->assertSame(0, FileReadLimit::ceilingBytes(32 * 1024 * 1024));
		$this->assertSame(0, FileReadLimit::ceilingBytes(FileReadLimit::MEMORY_RESERVE_BYTES));
		$this->assertSame(0, FileReadLimit::ceilingBytes(0));
	}

	public function testInvalidStoredValueFallsBackToTwentyMib(): void {
		$stored = new InMemoryConfig();
		$limit = new FileReadLimit($stored->mock($this));
		foreach (['0', '-5', 'abc', ''] as $value) {
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = $value;
			$this->assertSame(FileReadLimit::DEFAULT_MAX_BYTES, $limit->configuredMaxBytes(), $value);
		}
		unset($stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY]);
		$this->assertSame(20 * 1024 * 1024, $limit->configuredMaxBytes());
	}

	/** Live ini values: -1 applies the configured ceiling; M and G suffixes cap it. */
	public function testEffectiveLimitFollowsMemoryLimit(): void {
		$previous = (string)ini_get('memory_limit');
		$stored = new InMemoryConfig();
		$limit = new FileReadLimit($stored->mock($this));
		try {
			$this->assertNotFalse(ini_set('memory_limit', '128M'));
			$this->assertSame(128 * 1024 * 1024, $limit->phpMaxBytes());
			$this->assertFalse($limit->phpUnlimited());
			$this->assertSame(16 * 1024 * 1024, $limit->maxBytes(), 'the 20 MiB default is capped on a 128M host');
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = (string)(10 * 1024 * 1024);
			$this->assertSame(10 * 1024 * 1024, $limit->maxBytes());
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = (string)(200 * 1024 * 1024);
			$this->assertSame(16 * 1024 * 1024, $limit->maxBytes());

			$this->assertNotFalse(ini_set('memory_limit', '1G'));
			$this->assertSame(1024 ** 3, $limit->phpMaxBytes());
			$this->assertSame(173364565, $limit->maxBytes());

			$this->assertNotFalse(ini_set('memory_limit', '256M'));
			$this->assertSame(256 * 1024 * 1024, $limit->phpMaxBytes());
			$this->assertSame(FileReadLimit::ceilingBytes(256 * 1024 * 1024), $limit->maxBytes());

			$this->assertNotFalse(ini_set('memory_limit', '-1'));
			$this->assertTrue($limit->phpUnlimited());
			$this->assertSame(0, $limit->phpMaxBytes());
			$this->assertSame(200 * 1024 * 1024, $limit->maxBytes());

			if (memory_get_usage(true) < 32 * 1024 * 1024) {
				$this->assertNotFalse(ini_set('memory_limit', '32M'));
				$this->assertSame(0, $limit->maxBytes());
			}
		} finally {
			ini_set('memory_limit', $previous);
		}
	}
}
