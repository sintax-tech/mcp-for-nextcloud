<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

require_once __DIR__ . '/AssetTargets.php';
require_once __DIR__ . '/JsModuleSyntax.php';
require_once __DIR__ . '/ManifestDocument.php';
require_once __DIR__ . '/SourceCoverage.php';
require_once __DIR__ . '/BundleText.php';
require_once __DIR__ . '/AtomicReplace.php';

use RuntimeException;

/**
 * Builds `js/mcp-admin.js` and `js/mcp-personal.js` from the manifests under `src/`.
 *
 * Nothing is written while any source is an orphan, any entry is missing or escaping, any line is duplicated,
 * an admin fragment is listed in the personal manifest, or a fragment uses `import` / `export` / `require`.
 * The required manifests are {@see AssetTargets::REQUIRED}. A manifest that is not required and is absent is
 * skipped; one that is present is always checked, so deleting a file cannot turn the check off.
 */
final class AssetBuilder {
	/**
	 * Problems that stop a build, sorted. Nothing is written.
	 *
	 * @param string $root app directory (the working directory of `package.sh`)
	 * @param list<string>|null $required manifest paths relative to the app directory; null uses {@see AssetTargets::REQUIRED}
	 * @return list<string> empty when the tree may be built
	 */
	public static function problems(string $root, ?array $required = null): array {
		return self::plan($root, $required ?? AssetTargets::REQUIRED)['problems'];
	}

	/**
	 * Problems of the tree `check_package.php` is packaging. The required list is the constant, not the disk.
	 *
	 * @param string $root app directory
	 * @return list<string> empty when every present manifest covers `src/**\/*.js`
	 */
	public static function packageProblems(string $root): array {
		return self::problems($root);
	}

	/**
	 * Bundle bytes for a tree that is clean enough to build, keyed by archive member name.
	 *
	 * Null when the tree itself has a problem, so a caller does not compare an archive against a build that
	 * must not be published. An empty list means there is no manifest to package.
	 *
	 * @param string $root app directory
	 * @return array<string, string>|null `mcp/js/...` => bundle bytes, or null when the tree cannot be built
	 */
	public static function expectedBundles(string $root): ?array {
		$plan = self::plan($root, AssetTargets::REQUIRED);
		if ($plan['problems'] !== []) {
			return null;
		}
		$files = [];
		foreach ($plan['bundles'] as $target => $sources) {
			$files['mcp/' . $target] = BundleText::render($root, $sources);
		}
		return $files;
	}

	/**
	 * Writes both bundles, or writes nothing and returns the problems.
	 *
	 * @param string $root app directory
	 * @param list<string>|null $required manifest paths; null uses {@see AssetTargets::REQUIRED}
	 * @param int|null $interruptAfter passed to {@see AtomicReplace::replaceAll()} so a test can abort after staging
	 * @return list<string> empty when the bundles were published
	 */
	public static function build(string $root, ?array $required = null, ?int $interruptAfter = null): array {
		$plan = self::plan($root, $required ?? AssetTargets::REQUIRED);
		if ($plan['problems'] !== []) {
			return $plan['problems'];
		}
		$files = [];
		foreach ($plan['bundles'] as $target => $sources) {
			$files[$root . '/' . $target] = BundleText::render($root, $sources);
		}
		try {
			AtomicReplace::replaceAll($files, $interruptAfter);
		} catch (RuntimeException $error) {
			return [$error->getMessage()];
		}
		return [];
	}

	/**
	 * Problems, plus a stale or missing bundle when the sources themselves are acceptable.
	 *
	 * Only manifests that exist (and every required one) produce a bundle. With none, the tree is fresh.
	 *
	 * @param string $root app directory
	 * @param list<string>|null $required manifest paths; null uses {@see AssetTargets::REQUIRED}
	 * @return list<string> empty when every committed bundle matches a rebuild
	 */
	public static function freshnessProblems(string $root, ?array $required = null): array {
		$plan = self::plan($root, $required ?? AssetTargets::REQUIRED);
		if ($plan['problems'] !== []) {
			return $plan['problems'];
		}
		$problems = [];
		foreach ($plan['bundles'] as $target => $sources) {
			$expected = BundleText::render($root, $sources);
			$path = $root . '/' . $target;
			$actual = is_file($path) ? file_get_contents($path) : null;
			if ($actual !== $expected) {
				$problems[] = "stale bundle: $target";
			}
		}
		return $problems;
	}

	/**
	 * The manifests to build and every problem in the tree.
	 *
	 * @param string $root app directory
	 * @param list<string> $required manifest paths that must exist
	 * @return array{problems: list<string>, bundles: array<string, list<string>>} bundle path => fragment paths
	 */
	private static function plan(string $root, array $required): array {
		if ($root === '' || !is_dir($root)) {
			return ['problems' => ['cannot read the app directory'], 'bundles' => []];
		}
		$problems = [];
		$bundles = [];
		$covered = [];
		foreach (AssetTargets::MANIFESTS as $manifest => $bundle) {
			if (!is_file($root . '/' . $manifest)) {
				if (in_array($manifest, $required, true)) {
					$problems[] = "missing required manifest: $manifest";
				}
				continue;
			}
			$read = ManifestDocument::read($root, $manifest);
			array_push($problems, ...$read['problems']);
			$bundles[$bundle] = $read['paths'];
			array_push($covered, ...$read['paths']);
		}
		array_push($problems, ...SourceCoverage::problems($root, $covered));
		$seen = [];
		foreach ($bundles as $sources) {
			foreach ($sources as $source) {
				if (isset($seen[$source]) || !SourceCoverage::inside($root, $source)) {
					continue;
				}
				$seen[$source] = true;
				$body = file_get_contents($root . '/src/' . $source);
				$hit = JsModuleSyntax::violation($body === false ? '' : $body);
				if ($hit !== null) {
					$problems[] = "forbidden module syntax: $hit in $source";
				}
			}
		}
		$problems = array_values(array_unique($problems));
		sort($problems, SORT_STRING);
		return ['problems' => $problems, 'bundles' => $bundles];
	}
}
