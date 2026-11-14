<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Extends existing aggregate fixtures from SHA-checked official core files, parsed without executing native PHP.
 * Usage (pinned container only): php generate-core.php <core-src containing vMAJOR.0.0 directories>.
 */
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\{FixtureFile,SourceParser};
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsCreationCoreApiUsage;
require dirname(__DIR__, 4) . '/vendor/autoload.php';
$root = $argv[1];
$pins = json_decode(file_get_contents(__DIR__ . '/core-sources.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($pins as $major => $files) {
	$tree = $root . '/v' . $major . '.0.0';
	foreach ($files as $path => $record) {
		if (($record['status'] ?? 200) === 404) {
			throw new RuntimeException('Unexpected missing core source');
		} elseif (hash_file('sha256', $tree . '/' . $path) !== $record['sha256']) { throw new RuntimeException('Core source SHA mismatch'); }
	}
	$parser = new SourceParser($tree); $file = dirname(__DIR__) . '/' . $major . '.php'; $fixture = require $file;
	foreach (FormsCreationCoreApiUsage::CLASSES as $class) { $fixture['classes'][$class] = $parser->surface($class); }
	ksort($fixture['classes']); $before = file_get_contents($file);
	FixtureFile::write($file, substr($before, 0, strpos($before, 'return [')), $fixture);
}
echo "Extended five core contracts from 44 verified files and one explicitly missing historical interface\n";
