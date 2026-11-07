<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Checks scripts/dev/fqcn-map.json and scripts/dev/size-inventory.json against the app folder on disk.
 *
 * Usage: `php scripts/dev/map_check.php [--tickets=<folder>]`, run from anywhere. `--tickets` points at the
 * folder holding one `<ticket-id>-<slug>` folder per restructuring ticket, so each transitional entry must name a
 * ticket that exists. Exit code 0 when everything matches, 1 with the problems on stderr, 2 on bad usage.
 */

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MapCoverage;
use OCA\Mcp\Scripts\Dev\SizeInventory;
use OCA\Mcp\Scripts\Dev\SourceTrees;

require __DIR__ . '/bootstrap.php';

$options = getopt('', ['tickets:']);
if ($options === false || count($argv) > 1 + count($options)) {
	fwrite(STDERR, "usage: php scripts/dev/map_check.php [--tickets=<folder>]\n");
	exit(2);
}
$app = dirname(__DIR__, 2);
try {
	$map = FqcnMap::fromJson((string)file_get_contents(__DIR__ . '/fqcn-map.json'));
	$inventory = SizeInventory::fromJson((string)file_get_contents(__DIR__ . '/size-inventory.json'));
	$coverage = new MapCoverage(SourceTrees::fromDirectory($app, ['lib']), $map, $inventory);
	$problems = $coverage->check(isset($options['tickets']) ? (string)$options['tickets'] : null);
} catch (RuntimeException $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
$counts = $coverage->counts();
printf("map: %d entries; above %d lines: %d final + %d transitional\n", count($map->entries), $inventory->limit, $counts['final'], $counts['transitional']);
foreach ($problems as $problem) {
	fwrite(STDERR, $problem . "\n");
}
exit($problems === [] ? 0 : 1);
