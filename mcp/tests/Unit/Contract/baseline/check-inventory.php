<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Compares a PHPUnit JUnit report with the RE-01 baseline of this directory ({@see \OCA\Mcp\Tests\Unit\Contract\Oracle\InventoryCheck}):
 * every baseline test must still run (a skipped or incomplete test did not run), the skipped tests must be exactly
 * those of skipped.txt and the failing tests exactly the environment failures (missing intl/zip/gd in php:8.3-cli).
 *
 * Usage: php tests/Unit/Contract/baseline/check-inventory.php <junit.xml> [<fqcn-map.tsv>]
 * The optional map (old FQCN TAB new FQCN, one per line, from RE-01b) renames baseline classes before comparing.
 * Exit 0 when the report holds the baseline; 1 otherwise, with the differences; 2 on bad usage.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use OCA\Mcp\Tests\Unit\Contract\Oracle\InventoryCheck;

if ($argc < 2 || !is_file($argv[1])) {
	fwrite(STDERR, "usage: php check-inventory.php <junit.xml> [<fqcn-map.tsv>]\n");
	exit(2);
}
$map = [];
if (isset($argv[2])) {
	foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
		[$old, $new] = explode("\t", $line, 2) + [1 => ''];
		$map[$old] = $new;
	}
}
$check = InventoryCheck::fromBaseline(__DIR__, $map);
$ok = $check->check((string)file_get_contents($argv[1]));
printf("ran %d tests, %d failing, %d skipped (baseline %d tests, %d failing, %d skipped)\n", $check->ran, $check->failing,
	$check->skipped, count(file(__DIR__ . '/tests-inventory.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
	count(file(__DIR__ . '/environment-failures.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), count(file(__DIR__ . '/skipped.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []));
foreach ($check->problems as $problem) {
	echo $problem, "\n";
}
exit($ok ? 0 : 1);
