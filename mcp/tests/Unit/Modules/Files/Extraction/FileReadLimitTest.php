<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Extraction;

use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

/** The memory ceiling limits only a setting above 20 MiB, and never below that default. */
final class FileReadLimitTest extends TestCase {
	public function testCeilingLeavesRoomForTheParserCopies(): void {
		$this->assertSame(16 * 1024 * 1024, FileReadLimit::ceilingBytes(128 * 1024 * 1024));
		$this->assertSame(173364565, FileReadLimit::ceilingBytes(1024 ** 3));
		$this->assertSame(39146837, FileReadLimit::ceilingBytes(256 * 1024 * 1024));
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

	/** A ceiling under 20 MiB does not shrink the default, and an unreadable directive never becomes zero. */
	public function testEffectiveLimitFloorsAtTheDefault(): void {
		$default = FileReadLimit::DEFAULT_MAX_BYTES;
		$ten = 10 * 1024 * 1024;
		$twoHundred = 200 * 1024 * 1024;
		$this->assertSame($default, FileReadLimit::effective($default, '128M'));
		$this->assertSame($ten, FileReadLimit::effective($ten, '128M'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '128M'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '0x8000000'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '+128M'));
		$this->assertSame(173364565, FileReadLimit::effective($twoHundred, '1G'));
		$this->assertSame(39146837, FileReadLimit::effective($twoHundred, '256M'));
		$this->assertSame($twoHundred, FileReadLimit::effective($twoHundred, '-1'));
		$this->assertSame($twoHundred, FileReadLimit::effective($twoHundred, '  '));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '32M'));
		$this->assertSame($ten, FileReadLimit::effective($ten, '32M'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, 'nope'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '1T'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '256MB'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '0'));
		$this->assertSame($default, FileReadLimit::effective($twoHundred, '-2'));
		$this->assertSame(80 * 1024 * 1024, FileReadLimit::effective($default, '128M') * 4, 'the default PDF decode allowance stays 80 MiB');
	}

	/** An unreadable directive and a zero size are not a lower ceiling. A readable size, including -1, is not rejected. */
	public function testAnUnreadableMemoryLimitIsRejected(): void {
		$twoHundred = 200 * 1024 * 1024;
		foreach (['nope', '0', '-2'] as $value) {
			$this->assertTrue(FileReadLimit::memoryRejected($value), $value);
			$this->assertSame(FileReadLimit::DEFAULT_MAX_BYTES, FileReadLimit::effective($twoHundred, $value), $value);
		}
		foreach (['-1', '', '  ', '128M', '1G', '256MB', '1T', '0x8000000'] as $value) {
			$this->assertFalse(FileReadLimit::memoryRejected($value), $value);
		}
	}

	/** Live ini values agree with effective(): -1 applies the setting; M and G suffixes cap only what is above 20 MiB. */
	public function testEffectiveLimitFollowsMemoryLimit(): void {
		$previous = (string)ini_get('memory_limit');
		$stored = new InMemoryConfig();
		$limit = new FileReadLimit($stored->mock($this));
		try {
			$this->assertNotFalse(ini_set('memory_limit', '128M'));
			$this->assertSame(128 * 1024 * 1024, $limit->phpMaxBytes());
			$this->assertFalse($limit->phpUnlimited());
			$this->assertFalse($limit->phpRejected());
			$this->assertSame(FileReadLimit::DEFAULT_MAX_BYTES, $limit->maxBytes());
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = (string)(10 * 1024 * 1024);
			$this->assertSame(10 * 1024 * 1024, $limit->maxBytes());
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = (string)(200 * 1024 * 1024);
			$this->assertSame(FileReadLimit::DEFAULT_MAX_BYTES, $limit->maxBytes());

			$this->assertNotFalse(ini_set('memory_limit', '1G'));
			$this->assertSame(1024 ** 3, $limit->phpMaxBytes());
			$this->assertSame(173364565, $limit->maxBytes());

			$this->assertNotFalse(ini_set('memory_limit', '256M'));
			$this->assertSame(256 * 1024 * 1024, $limit->phpMaxBytes());
			$this->assertSame(39146837, $limit->maxBytes());

			$this->assertNotFalse(ini_set('memory_limit', '-1'));
			$this->assertTrue($limit->phpUnlimited());
			$this->assertFalse($limit->phpRejected());
			$this->assertSame(0, $limit->phpMaxBytes());
			$this->assertSame(200 * 1024 * 1024, $limit->maxBytes());
		} finally {
			ini_set('memory_limit', $previous);
		}
	}
}
