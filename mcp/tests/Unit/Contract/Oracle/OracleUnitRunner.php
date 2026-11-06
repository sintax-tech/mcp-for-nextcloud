<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

/**
 * Executes Unit in the RE-01 runtime, normally php:8.3-cli, without changing app code or frozen transcripts.
 * Usage from mcp/: php tests/Unit/Contract/Oracle/OracleUnitRunner.php [PHPUnit arguments].
 * post_max_size/upload_max_filesize are startup-only directives: PHPUnit XML and ini_set cannot override them.
 */
final class OracleUnitRunner {
	/** Static CLI entry point only. */
	private function __construct() {
	}

	/**
	 * Pins startup directives on the child PHP process and forwards its exact status.
	 *
	 * @param list<string> $arguments Optional PHPUnit paths, filters or report arguments; defaults to tests/Unit.
	 * @return int PHPUnit status, or two when the host runtime cannot reproduce the oracle.
	 */
	public static function run(array $arguments): int {
		if (class_exists(\IntlDateFormatter::class)) {
			fwrite(STDERR, "runtime não canônico: ext-intl presente; execute no runtime do oracle sem IntlDateFormatter\n");
			return 2;
		}
		$app = dirname(__DIR__, 4);
		$command = [PHP_BINARY, '-d', 'memory_limit=1G', '-d', 'post_max_size=8M', '-d', 'upload_max_filesize=2M',
			$app . '/vendor/bin/phpunit', '-c', $app . '/phpunit.xml', '--do-not-cache-result',
			...($arguments === [] ? [$app . '/tests/Unit'] : $arguments)];
		passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
		return $status;
	}
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	exit(OracleUnitRunner::run(array_slice($argv, 1)));
}

