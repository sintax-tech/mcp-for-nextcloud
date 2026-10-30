<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Compares a PHPUnit JUnit report with the RE-01 baseline of this directory: every baseline test identity
 * (`Class::method`, data set included) must still run, and the set of failing tests must be exactly the
 * environment failures of the baseline (missing intl/zip/gd in php:8.3-cli).
 *
 * Usage: php tests/Unit/Contract/baseline/check-inventory.php <junit.xml> [<fqcn-map.tsv>]
 * The optional map (old FQCN TAB new FQCN, one per line, from RE-01b) renames baseline classes before comparing.
 * Exit 0 when the report contains the baseline and fails exactly where it did; 1 otherwise, with the differences.
 */

/**
 * @param string $file a text file of this directory
 * @return list<string> its non-empty lines
 */
function baselineLines(string $file): array {
	return array_values(array_filter(explode("\n", (string)file_get_contents(__DIR__ . '/' . $file)), static fn (string $l): bool => $l !== ''));
}

/**
 * @param string $id baseline identity `Class::method…`
 * @param array<string, string> $map old FQCN => new FQCN
 * @return string the identity under the new class name
 */
function renamed(string $id, array $map): string {
	[$class, $rest] = explode('::', $id, 2) + [1 => ''];
	return ($map[$class] ?? $class) . '::' . $rest;
}

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
$report = simplexml_load_file($argv[1]);
$ran = [];
$failing = [];
foreach ($report->xpath('//testcase') ?: [] as $case) {
	$id = (string)$case['class'] . '::' . (string)$case['name'];
	$ran[$id] = true;
	if (isset($case->failure) || isset($case->error)) {
		$failing[$id] = true;
	}
}
$missing = array_values(array_filter(array_map(static fn (string $id): string => renamed($id, $map), baselineLines('tests-inventory.txt')),
	static fn (string $id): bool => !isset($ran[$id])));
$expected = array_map(static fn (string $line): string => renamed(explode("\t", $line)[0], $map), baselineLines('environment-failures.txt'));
$newFailures = array_values(array_diff(array_keys($failing), $expected));
$fixed = array_values(array_diff($expected, array_keys($failing)));
printf("ran %d tests (baseline %d), %d failing (baseline %d)\n", count($ran), count(baselineLines('tests-inventory.txt')), count($failing), count($expected));
foreach (['missing from the run' => $missing, 'failing but not in the baseline' => $newFailures, 'baseline failure no longer failing' => $fixed] as $label => $ids) {
	foreach ($ids as $id) {
		echo $label, ': ', $id, "\n";
	}
}
exit($missing === [] && $newFailures === [] && $fixed === [] ? 0 : 1);
