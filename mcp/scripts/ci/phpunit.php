<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Scripts\Ci\PhpunitCoverage;
use OCA\Mcp\Scripts\Ci\PhpunitProfiles;

require_once __DIR__ . '/PhpunitCoverage.php';
require_once __DIR__ . '/PhpunitProfiles.php';

// Usage: php scripts/ci/phpunit.php prepare|verify|run <report-directory> [profile].
try {
	$app = dirname(__DIR__, 2);
	$work = $argv[2] ?? throw new RuntimeException('A disposable report directory is required');
	switch ($argv[1] ?? '') {
		case 'prepare':
			if (!is_dir($work)) { mkdir($work, 0777, true); }
			PhpunitProfiles::configure($app, $work);
			$status = PhpunitProfiles::execute([PHP_BINARY, $app . '/vendor/bin/phpunit', '-c', $app . '/phpunit.xml', '--list-tests-xml', $work . '/inventory.xml']);
			if ($status !== 0) { exit($status); }
			PhpunitCoverage::prepare($work, PhpunitProfiles::filters());
			break;
		case 'run':
			exit(PhpunitProfiles::run($app, $work, $argv[3] ?? ''));
		case 'verify':
			PhpunitCoverage::verify($work);
			break;
		default:
			throw new RuntimeException('Expected prepare, run or verify');
	}
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
}
