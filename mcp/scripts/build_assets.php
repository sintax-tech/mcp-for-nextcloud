<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Builds the admin and personal bundles from `src/*.manifest` when those files exist.
 *
 * Usage, from the app directory: `php scripts/build_assets.php`. Exit 0 writes the bundles (or writes nothing
 * when no manifest exists and `src/` has no script). Exit 1 prints every problem on stderr and leaves every
 * bundle untouched. Exit 2 is bad usage. There is no Node, npm or esbuild step.
 */

use OCA\Mcp\Scripts\AssetBuilder;

require_once __DIR__ . '/AssetBuilder.php';

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	if ($argc !== 1) {
		fwrite(STDERR, "usage: php scripts/build_assets.php\n");
		exit(2);
	}
	$root = getcwd();
	$problems = AssetBuilder::build($root === false ? '' : $root);
	foreach ($problems as $problem) {
		fwrite(STDERR, $problem . "\n");
	}
	exit($problems === [] ? 0 : 1);
}
