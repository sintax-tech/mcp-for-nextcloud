<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use RuntimeException;

/**
 * Aborts one development-script test that runs past its wall-clock limit.
 *
 * PHPUnit prints a progress line only every 63 tests, so a stuck test in the
 * repository move checks leaves the CI log on the previous line until the job
 * is cancelled. The limit fails that test by name. `MCP_DEV_TEST_TIMEOUT=0`
 * turns the limit off; any other integer replaces the default of 600 seconds.
 */
final class DevTestTimeout {
	/** Tests this limit applies to. */
	public const PREFIX = 'OCA\\Mcp\\Tests\\Unit\\Scripts\\Dev\\';

	private const DEFAULT_SECONDS = 600;

	private static int $limit = self::DEFAULT_SECONDS;

	private static int $armedFor = 0;

	private static ?string $current = null;

	private static bool $registered = false;

	private static bool $installed = false;

	/**
	 * Subscribes to the PHPUnit run. Called from the test bootstrap, before the facade is sealed.
	 *
	 * @return void
	 */
	public static function register(): void {
		if (self::$registered) {
			return;
		}
		self::$registered = true;
		$env = getenv('MCP_DEV_TEST_TIMEOUT');
		if ($env === '0') {
			return;
		}
		if ($env !== false && $env !== '') {
			self::$limit = (int)$env;
		}
		if (self::$limit < 1 || !function_exists('pcntl_alarm')) {
			if (!function_exists('pcntl_alarm')) {
				fwrite(STDERR, "Dev test time limit is inactive because the pcntl extension is not loaded\n");
			}
			return;
		}
		$facade = Facade::instance();
		$facade->registerSubscriber(new DevTestTimeoutStart());
		$facade->registerSubscriber(new DevTestTimeoutStop());
	}

	/**
	 * Seconds configured for the suite.
	 *
	 * @return int positive limit, or zero when the limit is off
	 */
	public static function seconds(): int {
		return self::$limit;
	}

	/**
	 * Starts the alarm for one test.
	 *
	 * @param string $name test id printed if the alarm fires
	 * @param int $seconds wall-clock budget
	 * @return void
	 */
	public static function arm(string $name, int $seconds): void {
		if ($seconds < 1 || !function_exists('pcntl_alarm')) {
			return;
		}
		self::install();
		self::$current = $name;
		self::$armedFor = $seconds;
		pcntl_alarm($seconds);
	}

	/**
	 * Cancels the alarm. The handler stays installed for the next test.
	 *
	 * @return void
	 */
	public static function disarm(): void {
		if (function_exists('pcntl_alarm')) {
			pcntl_alarm(0);
		}
		self::$current = null;
	}

	/**
	 * Installs the SIGALRM handler once.
	 *
	 * @return void
	 */
	private static function install(): void {
		if (self::$installed) {
			return;
		}
		pcntl_async_signals(true);
		pcntl_signal(SIGALRM, static function (): void {
			$name = self::$current ?? 'unknown dev test';
			$seconds = self::$armedFor;
			pcntl_alarm(0);
			fwrite(STDERR, "Dev test timed out after {$seconds}s: {$name}\n");
			fflush(STDERR);
			throw new RuntimeException("Dev test timed out after {$seconds}s: {$name}");
		}, true);
		self::$installed = true;
	}
}

/** Arms the limit when a development-script test starts and names it on stderr. */
final class DevTestTimeoutStart implements PreparedSubscriber {
	/**
	 * @param Prepared $event test about to run
	 * @return void
	 */
	public function notify(Prepared $event): void {
		$id = $event->test()->id();
		if (!str_starts_with($id, DevTestTimeout::PREFIX)) {
			DevTestTimeout::disarm();
			return;
		}
		fwrite(STDERR, "dev test: {$id}\n");
		fflush(STDERR);
		DevTestTimeout::arm($id, DevTestTimeout::seconds());
	}
}

/** Cancels the alarm when the test ends, including failures. */
final class DevTestTimeoutStop implements FinishedSubscriber {
	/**
	 * @param Finished $event test that finished
	 * @return void
	 */
	public function notify(Finished $event): void {
		DevTestTimeout::disarm();
	}
}
