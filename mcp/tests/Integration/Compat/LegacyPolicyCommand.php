<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use InvalidArgumentException;
use Throwable;

/** Keeps the historical read process separate from installed-release seeding and fails closed after core bootstrap. */
final class LegacyPolicyCommand {
	/**
	 * @param string $mode Validated CLI mode: seed the installed baseline, or read the displaced historical baseline.
	 * @param string $output Private checkpoint destination; no credential contents are logged.
	 * @return void The read path never loads the candidate probe or resolves its container services.
	 * @throws InvalidArgumentException for any mode outside the closed CLI contract
	 */
	public static function prepare(string $mode, string $output): void {
		// Core's CLI exception handler can exit successfully. Register after lib/base.php so failures reach the caller.
		set_exception_handler(static function (Throwable $error): void {
			fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
			exit(1);
		});
		if ($mode === 'read') {
			return;
		}
		if ($mode !== 'seed') {
			throw new InvalidArgumentException('Unknown legacy policy command mode');
		}
		require_once __DIR__ . '/ReleaseUpgradeProbe.php';
		ReleaseUpgradeProbe::checkpoint('policy', $output);
	}
}
