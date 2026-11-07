<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Throwable;

/**
 * Run all restoration checks after cleanup, preserving the scenario's original failure.
 */
final class GuestScenario {
	/**
	 * Failures collected while restoring independent resources.
	 *
	 * @var list<Throwable>
	 */
	private array $failures = [];
	/**
	 * Checks deferred until every cleanup attempt has completed.
	 *
	 * @var list<callable(): void>
	 */
	private array $checks = [];

	/**
	 * Execute a scenario, attempt its cleanup, then report the original failure first.
	 *
	 * @param callable(): void $body Scenario assertions and mutations.
	 * @param callable(): void $cleanup Independent restoration attempts.
	 * @return void
	 */
	public function run(callable $body, callable $cleanup): void {
		$this->checks = [];
		$this->failures = [];
		$original = null;
		try {
			$body();
		} catch (Throwable $failure) {
			$original = $failure;
		}
		$this->cleanup($cleanup);
		foreach ($this->checks as $check) {
			$this->cleanup($check);
		}
		$first = $original ?? array_shift($this->failures);
		if ($first === null) {
			return;
		}
		if ($this->failures === []) {
			throw $first;
		}
		$message = $first->getMessage() . "\nAdditional cleanup failures:";
		foreach ($this->failures as $failure) {
			$message .= "\n" . $failure::class . ': ' . $failure->getMessage();
		}
		// Keep assertion failures as failures, runtime errors as errors, and the original exception as cause.
		if ($first instanceof AssertionFailedError) {
			throw new AssertionFailedError($message, 0, $first);
		}
		throw new RuntimeException($message, 0, $first);
	}

	/**
	 * Attempt one independent restoration without masking an in-flight scenario failure.
	 *
	 * @param callable(): void $cleanup Restoration to attempt even if another restoration failed.
	 * @return void
	 */
	public function cleanup(callable $cleanup): void {
		try {
			$cleanup();
		} catch (Throwable $failure) {
			$this->failures[] = $failure;
		}
	}

	/**
	 * Defer an assertion until all cleanup and restoration attempts have finished.
	 *
	 * @param callable(): void $check Assertion using the state captured immediately after restoration.
	 * @return void
	 */
	public function check(callable $check): void {
		$this->checks[] = $check;
	}
}
