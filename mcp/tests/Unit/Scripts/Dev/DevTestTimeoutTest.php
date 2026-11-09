<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves a stuck development-script test is aborted by name instead of hanging the job.
 */
final class DevTestTimeoutTest extends TestCase {
	/**
	 * The alarm interrupts a blocking call and names the test.
	 *
	 * @return void
	 */
	public function testAStuckDevTestIsAbortedWithItsName(): void {
		if (!function_exists('pcntl_alarm')) {
			if (getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true') {
				$this->fail('pcntl is required so a stuck dev test fails instead of hanging the job');
			}
			$this->markTestSkipped('pcntl is not loaded');
		}
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dev test timed out after 1s: probe');
		DevTestTimeout::arm('probe', 1);
		try {
			sleep(5);
		} finally {
			DevTestTimeout::disarm();
		}
	}
}
