<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Support;

use OCA\Mcp\Tests\Integration\Guests\GuestScenario;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Verify failure precedence and independent cleanup attempts without booting Nextcloud.
 */
final class GuestScenarioTest extends TestCase {
	/**
	 * Keep the original failure object when restoration succeeds.
	 *
	 * @return void
	 */
	public function testOriginalFailureSurvivesSuccessfulCleanup(): void {
		$original = new AssertionFailedError('body failed');
		$caught = $this->capture(fn () => (new GuestScenario())->run(
			static fn () => throw $original,
			static function (): void {},
		));
		$this->assertSame($original, $caught);
	}

	/**
	 * Preserve the original assertion and append cleanup diagnostics after every attempt.
	 *
	 * @return void
	 */
	public function testBodyFailurePrecedesCleanupFailuresAndAllChecksRun(): void {
		$scenario = new GuestScenario();
		$events = [];
		$original = new AssertionFailedError('original assertion');
		$caught = $this->capture(function () use ($scenario, &$events, $original): void {
			$scenario->run(function () use ($scenario, $original, &$events): void {
				$scenario->check(function () use (&$events): void {
					$events[] = 'check';
					throw new AssertionFailedError('restoration mismatch');
				});
				throw $original;
			}, function () use ($scenario, &$events): void {
				$scenario->cleanup(static fn () => throw new RuntimeException('cleanup failed'));
				$events[] = 'restored';
			});
		});
		$this->assertInstanceOf(AssertionFailedError::class, $caught);
		$this->assertSame($original, $caught->getPrevious());
		$this->assertStringStartsWith('original assertion', $caught->getMessage());
		$this->assertStringContainsString('cleanup failed', $caught->getMessage());
		$this->assertStringContainsString('restoration mismatch', $caught->getMessage());
		$this->assertSame(['restored', 'check'], $events);
	}

	/**
	 * Report a cleanup failure even when the scenario body succeeds.
	 *
	 * @return void
	 */
	public function testCleanupOnlyFailureIsNotIgnored(): void {
		$failure = new RuntimeException('cleanup only');
		$caught = $this->capture(fn () => (new GuestScenario())->run(
			static function (): void {},
			static fn () => throw $failure,
		));
		$this->assertSame($failure, $caught);
	}

	/**
	 * Keep runtime failure classification and include cleanup assertion diagnostics.
	 *
	 * @return void
	 */
	public function testRuntimeFailureKeepsItsCauseWhenCleanupAlsoFails(): void {
		$original = new RuntimeException('body error');
		$caught = $this->capture(fn () => (new GuestScenario())->run(
			static fn () => throw $original,
			static fn () => throw new AssertionFailedError('cleanup assertion'),
		));
		$this->assertInstanceOf(RuntimeException::class, $caught);
		$this->assertNotInstanceOf(AssertionFailedError::class, $caught);
		$this->assertSame($original, $caught->getPrevious());
		$this->assertStringStartsWith('body error', $caught->getMessage());
		$this->assertStringContainsString('cleanup assertion', $caught->getMessage());
	}

	/**
	 * Report a deferred assertion even when the scenario body and cleanup succeed.
	 *
	 * @return void
	 */
	public function testSuccessfulBodyStillReportsFailedCheck(): void {
		$scenario = new GuestScenario();
		$failure = new AssertionFailedError('deferred check failed');
		$caught = $this->capture(fn () => $scenario->run(
			static fn () => $scenario->check(static fn () => throw $failure),
			static function (): void {},
		));
		$this->assertSame($failure, $caught);
	}

	/**
	 * Reset both pending checks and cleanup failures before reusing the same scenario.
	 *
	 * @return void
	 */
	public function testReuseDoesNotRetainChecksOrFailures(): void {
		$scenario = new GuestScenario();
		$original = new AssertionFailedError('first body failed');
		$checked = 0;
		$caught = $this->capture(function () use ($scenario, $original, &$checked): void {
			$scenario->run(function () use ($scenario, $original, &$checked): void {
				$scenario->check(function () use (&$checked): void {
					$checked++;
				});
				throw $original;
			}, static fn () => throw new RuntimeException('first cleanup failed'));
		});
		$this->assertSame($original, $caught->getPrevious());
		$scenario->run(static function (): void {}, static function (): void {});
		$this->assertSame(1, $checked, 'A previous run must not replay its deferred checks');
	}

	/**
	 * Capture an expected failure without losing assertions about its identity and diagnostics.
	 *
	 * @param callable(): void $action Scenario expected to fail.
	 * @return Throwable Captured failure.
	 */
	private function capture(callable $action): Throwable {
		try {
			$action();
		} catch (Throwable $failure) {
			return $failure;
		}
		$this->fail('The scenario must report its failure');
	}
}
