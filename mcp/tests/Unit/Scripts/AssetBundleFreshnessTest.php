<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use FilesystemIterator;
use OCA\Mcp\Scripts\AssetBuilder;
use OCA\Mcp\Scripts\AssetTargets;
use OCA\Mcp\Scripts\SourceCoverage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/../../../scripts/AssetBuilder.php';

/**
 * Covers bundle freshness and the constant list of manifests that must exist.
 *
 * The list is not discovered by reading `src/`. This ticket's list is empty, so a missing admin manifest is
 * not a failure until RE-11 adds `src/admin.manifest` here and on {@see AssetTargets::REQUIRED}.
 */
final class AssetBundleFreshnessTest extends TestCase {
	/**
	 * Manifests that must exist, as paths relative to the app directory.
	 *
	 * RE-11 adds `src/admin.manifest`. RE-12 adds `src/settings.manifest`.
	 *
	 * @var list<string>
	 */
	private const REQUIRED_MANIFESTS = [];

	/** @var list<string> temporary app directories created by this test */
	private array $dirs = [];

	/** Deletes only the directories this test created. */
	protected function tearDown(): void {
		foreach ($this->dirs as $dir) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST,
			);
			foreach ($files as $file) {
				$file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
			}
			rmdir($dir);
		}
	}

	/** The production constant and this test name the same manifests, and absence on disk does not shrink the list. */
	public function testRequiredManifestsAreAConstantNotADirectoryListing(): void {
		$this->assertSame(self::REQUIRED_MANIFESTS, AssetTargets::REQUIRED);
		$dir = sys_get_temp_dir() . '/mcp-fresh-' . bin2hex(random_bytes(4));
		mkdir($dir . '/src', 0777, true);
		$this->dirs[] = $dir;
		file_put_contents("$dir/src/other.manifest", "admin/a.js\n");
		$this->assertSame([], AssetBuilder::problems($dir, self::REQUIRED_MANIFESTS));
		$this->assertSame(
			['missing required manifest: src/admin.manifest'],
			AssetBuilder::problems($dir, ['src/admin.manifest']),
		);
	}

	/** A bundle that does not match its sources is stale, and a rebuild clears that. */
	public function testAStaleBundleFailsUntilRebuilt(): void {
		$dir = sys_get_temp_dir() . '/mcp-fresh-' . bin2hex(random_bytes(4));
		mkdir($dir . '/src/admin', 0777, true);
		mkdir($dir . '/js', 0777, true);
		$this->dirs[] = $dir;
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/admin.manifest", "admin/a.js\n");
		file_put_contents("$dir/js/mcp-admin.js", "var stale = 1\n");
		$this->assertSame(['stale bundle: js/mcp-admin.js'], AssetBuilder::freshnessProblems($dir));
		$this->assertSame([], AssetBuilder::build($dir));
		$this->assertSame([], AssetBuilder::freshnessProblems($dir));
	}

	/** This ticket migrates no script, so the application tree has nothing to bundle and is fresh. */
	public function testTheApplicationTreeIsFresh(): void {
		$root = dirname(__DIR__, 3);
		$this->assertSame([], SourceCoverage::javascript($root));
		$this->assertSame([], AssetBuilder::problems($root));
		$this->assertSame([], AssetBuilder::freshnessProblems($root));
	}
}
