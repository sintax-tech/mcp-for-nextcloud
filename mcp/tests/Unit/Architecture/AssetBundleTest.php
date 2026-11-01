<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Architecture;

use FilesystemIterator;
use OCA\Mcp\Scripts\AssetBuilder;
use OCA\Mcp\Scripts\AssetTargets;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/../../../scripts/AssetBuilder.php';

/**
 * The frontend bundle rules of the app tree: two generated scripts, one script registration per page, fresh bundles.
 *
 * RE-12 delivers this part of the RE-08 `AssetBundleTest`, already active: `js/` holds exactly `mcp-admin.js` and
 * `mcp-personal.js`, the admin and personal templates register their own bundle once and no other template registers a
 * script, and both bundles equal a rebuild from `src/`.
 */
final class AssetBundleTest extends TestCase {
	/** Template file name => the one script it registers. Every other template registers none. */
	private const SCRIPTS = ['admin.php' => 'mcp-admin', 'personal.php' => 'mcp-personal'];

	/**
	 * `js/` contains the two generated bundles and nothing else, at any depth.
	 *
	 * @return void
	 */
	public function testJsHoldsOnlyTheTwoGeneratedBundles(): void {
		$app = dirname(__DIR__, 3);
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app . '/js', FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$found[] = substr($file->getPathname(), strlen($app) + 1);
		}
		sort($found, SORT_STRING);
		$expected = array_values(AssetTargets::MANIFESTS);
		sort($expected, SORT_STRING);
		$this->assertSame($expected, $found);
		$this->assertSame(['js/mcp-admin.js', 'js/mcp-personal.js'], $found);
	}

	/**
	 * Each page registers its bundle once; no other template registers a script.
	 *
	 * @return void
	 */
	public function testEachTemplateRegistersOnlyItsOwnBundle(): void {
		$app = dirname(__DIR__, 3);
		foreach (glob($app . '/templates/*.php') ?: [] as $path) {
			preg_match_all("/Util::addScript\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", (string)file_get_contents($path), $matches);
			$expected = isset(self::SCRIPTS[basename($path)]) ? [self::SCRIPTS[basename($path)]] : [];
			$this->assertSame($expected, $matches[1], basename($path));
		}
	}

	/**
	 * Both manifests exist, every source is covered and both committed bundles equal a rebuild.
	 *
	 * @return void
	 */
	public function testBothBundlesAreFresh(): void {
		$app = dirname(__DIR__, 3);
		foreach (AssetTargets::REQUIRED as $manifest) {
			$this->assertFileExists($app . '/' . $manifest);
		}
		$this->assertSame([], AssetBuilder::problems($app));
		$this->assertSame([], AssetBuilder::freshnessProblems($app));
	}
}
