<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Test inventory of the restructuring: no baseline test may disappear across a move.
 *
 * Usage, run inside the Git work tree:
 * - `php scripts/dev/test_inventory.php [--rev=<rev>] snapshot` prints the `Class::method` identities as JSON;
 * - `php scripts/dev/test_inventory.php [--rev=<rev>] compare <baseline.json>` fails when a baseline identity has
 *   no counterpart, mapped through scripts/dev/fqcn-map.json of the same tree;
 * - `php scripts/dev/test_inventory.php failures <baseline-junit.xml> <current-junit.xml>` fails when the set of
 *   failing tests differs (the known environment failures must stay exactly the same).
 * Without `--rev` the app folder on disk is read. Exit code 0 on success, 1 on a difference, 2 on bad usage.
 */

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\JunitReport;
use OCA\Mcp\Scripts\Dev\SourceTrees;
use OCA\Mcp\Scripts\Dev\TestInventory;
use OCA\Mcp\Scripts\Dev\TestInventoryComparison;

require __DIR__ . '/bootstrap.php';

$rest = 0;
$options = getopt('', ['rev:', 'app:'], $rest);
$arguments = array_slice($argv, $rest);
$command = $arguments[0] ?? '';
$expected = ['snapshot' => 1, 'compare' => 2, 'failures' => 3];
if ($options === false || !isset($expected[$command]) || count($arguments) !== $expected[$command]) {
	fwrite(STDERR, "usage: php scripts/dev/test_inventory.php [--rev=<rev>] snapshot | compare <baseline.json> | failures <baseline.xml> <current.xml>\n");
	exit(2);
}
try {
	$files = isset($options['rev'])
		? SourceTrees::fromGit(__DIR__, (string)$options['rev'], (string)($options['app'] ?? 'mcp'))
		: SourceTrees::fromDirectory(dirname(__DIR__, 2), ['tests', 'scripts']);
	$map = FqcnMap::fromJson($files['scripts/dev/fqcn-map.json'] ?? throw new RuntimeException('no scripts/dev/fqcn-map.json in the tree'));
	$comparison = new TestInventoryComparison($map);
	if ($command === 'snapshot') {
		echo json_encode(TestInventory::scan($files), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		exit(0);
	}
	if ($command === 'compare') {
		$baseline = json_decode((string)file_get_contents($arguments[1]), true);
		if (!is_array($baseline)) {
			throw new RuntimeException("{$arguments[1]}: not a JSON list of identities");
		}
		$current = TestInventory::scan($files);
		$problems = array_map(static fn (string $id): string => "missing test: $id", $comparison->missing($baseline, $current));
		printf("%d baseline tests, %d now; matched exact %d, by map %d, by short name %d\n", count($baseline), count($current), ...array_values($comparison->rules));
	} else {
		$difference = $comparison->failures(JunitReport::fromXml((string)file_get_contents($arguments[1])), JunitReport::fromXml((string)file_get_contents($arguments[2])));
		$problems = [...array_map(static fn (string $id): string => "baseline failure no longer failing: $id", $difference['gone']), ...array_map(static fn (string $id): string => "new failure: $id", $difference['new'])];
		echo count($problems) === 0 ? "same failures\n" : '';
	}
} catch (RuntimeException $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
foreach ($problems as $problem) {
	fwrite(STDERR, $problem . "\n");
}
exit($problems === [] ? 0 : 1);
