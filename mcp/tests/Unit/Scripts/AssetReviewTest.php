<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use FilesystemIterator;
use OCA\Mcp\Scripts\AssetBuilder;
use OCA\Mcp\Scripts\AtomicReplace;
use OCA\Mcp\Scripts\PackageCheck;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/check_package.php';

/**
 * Negative cases from the RE-10 review: template interpolation, archive bytes, an admin symlink and a rolled-back publish.
 */
final class AssetReviewTest extends TestCase {
	/** @var list<string> temporary app directories created by {@see self::tree()} */
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

	/** require and import inside a template interpolation are code, including a nested template. */
	public function testModuleSyntaxInsideTemplateInterpolationIsRefused(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/require.js", 'const x = `${require("x")}`;' . "\n");
		file_put_contents("$dir/src/admin/import.js", 'const x = `${import("./x.js")}`;' . "\n");
		file_put_contents("$dir/src/admin/nested.js", 'const x = `${`${require("x")}`}`;' . "\n");
		file_put_contents("$dir/src/admin.manifest", "admin/require.js\nadmin/import.js\nadmin/nested.js\n");
		$this->assertSame([
			'forbidden module syntax: import in admin/import.js:1',
			'forbidden module syntax: require in admin/nested.js:1',
			'forbidden module syntax: require in admin/require.js:1',
		], AssetBuilder::build($dir));
		$this->assertFileDoesNotExist("$dir/js/mcp-admin.js");

		$clean = $this->tree();
		$policy = 'const a = `importantValue`;' . "\n";
		$policy .= 'const b = `exported`;' . "\n";
		$policy .= 'const c = `${ /* require */ 1 }`;' . "\n";
		$policy .= 'const d = `${`${1}`}`;' . "\n";
		file_put_contents("$clean/src/admin/policy.js", $policy);
		file_put_contents("$clean/src/admin.manifest", "admin/policy.js\n");
		$this->assertSame([], AssetBuilder::build($clean));
	}

	/** A personal entry whose real path is under src/admin/ is an admin fragment, whatever the link is called. */
	public function testASymlinkFromSharedIntoAdminIsRefusedInThePersonalManifest(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/private.js", "var privateKey = 1\n");
		symlink('../admin/private.js', "$dir/src/shared/alias.js");
		file_put_contents("$dir/src/admin.manifest", "admin/private.js\n");
		file_put_contents("$dir/src/settings.manifest", "shared/alias.js\n");
		$this->assertSame(['admin source in personal manifest: shared/alias.js'], AssetBuilder::build($dir));
		$this->assertFileDoesNotExist("$dir/js/mcp-personal.js");
		$this->assertFileDoesNotExist("$dir/js/mcp-admin.js");
	}

	/** A rename that fails after another destination was published puts that destination back. */
	public function testAFailedSecondRenameRollsBackTheFirstFile(): void {
		$dir = $this->tree();
		file_put_contents("$dir/js/a.js", 'OLD');
		mkdir("$dir/js/b.js");
		$thrown = null;
		try {
			AtomicReplace::replaceAll([
				"$dir/js/a.js" => 'NEW',
				"$dir/js/b.js" => 'NEW2',
			]);
		} catch (RuntimeException $error) {
			$thrown = $error;
		}
		$this->assertInstanceOf(RuntimeException::class, $thrown);
		$this->assertSame("cannot replace $dir/js/b.js", $thrown->getMessage());
		$this->assertSame('OLD', file_get_contents("$dir/js/a.js"));
		$this->assertDirectoryExists("$dir/js/b.js");
		$this->assertSame([], glob($dir . '/js/.*tmp') ?: []);
	}

	/** The two bundles are published together: a blocked second path leaves the first bundle untouched. */
	public function testAFailedSecondBundlePublishesNothing(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/settings/p.js", "var p = 1\n");
		file_put_contents("$dir/src/admin.manifest", "admin/a.js\n");
		file_put_contents("$dir/src/settings.manifest", "settings/p.js\n");
		file_put_contents("$dir/js/mcp-admin.js", 'OLD');
		mkdir("$dir/js/mcp-personal.js");
		$this->assertSame(
			["cannot replace $dir/js/mcp-personal.js"],
			AssetBuilder::build($dir),
		);
		$this->assertSame('OLD', file_get_contents("$dir/js/mcp-admin.js"));
		$this->assertDirectoryExists("$dir/js/mcp-personal.js");
		$this->assertSame([], glob($dir . '/js/.*tmp') ?: []);
	}

	/** The same member twice is refused, because tar installs the last copy. */
	public function testADuplicateArchiveMemberIsRefused(): void {
		$dir = $this->appWithAdminBundle();
		$good = "/* admin/a.js */\nvar a = 1\n";
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(
			self::ustar('mcp/js/mcp-admin.js', $good)
			. self::ustar('mcp/js/mcp-admin.js', "/* stale */\n")
			. str_repeat("\0", 1024),
		));
		$this->assertSame(
			['duplicate archive member: mcp/js/mcp-admin.js', 'packaged bundle differs: mcp/js/mcp-admin.js'],
			$this->packaged($dir, $tar),
		);
	}

	/** The checker reads the archive bytes: a stale bundle and a packaged src/ file both fail on a healthy tree. */
	public function testTheArchiveBytesAndAPackagedSourceAreRefused(): void {
		$dir = $this->appWithAdminBundle();
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(
			self::ustar('mcp/js/mcp-admin.js', "/* stale */\n")
			. self::ustar('mcp/src/admin/leaked.js', "var leaked = 1\n")
			. str_repeat("\0", 1024),
		));
		$this->assertSame(
			['packaged bundle differs: mcp/js/mcp-admin.js', 'packaged source: mcp/src/admin/leaked.js'],
			$this->packaged($dir, $tar),
		);
	}

	/** A manifest that builds a bundle is not satisfied by an archive that omits that member. */
	public function testAMissingPackagedBundleIsRefused(): void {
		$dir = $this->appWithAdminBundle();
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(self::ustar('mcp/appinfo/info.xml', '<info/>') . str_repeat("\0", 1024)));
		$this->assertSame(['missing packaged bundle: mcp/js/mcp-admin.js'], $this->packaged($dir, $tar));
	}

	/** A bundle whose bytes are the manifest build, and an archive without src/, passes the archive gate. */
	public function testAMatchingArchivePassesTheBundleGate(): void {
		$dir = $this->appWithAdminBundle();
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(
			self::ustar('mcp/js/mcp-admin.js', "/* admin/a.js */\nvar a = 1\n")
			. str_repeat("\0", 1024),
		));
		$this->assertSame([], $this->packaged($dir, $tar));
	}

	/**
	 * Archive problems that are about the packaged bundles and sources, sorted.
	 *
	 * @return list<string>
	 */
	private function packaged(string $dir, string $tar): array {
		$cwd = getcwd();
		chdir($dir);
		try {
			$problems = PackageCheck::problems($tar, []);
		} finally {
			chdir($cwd === false ? $dir : $cwd);
		}
		$asset = array_values(array_filter(
			$problems,
			static fn (string $problem): bool => str_starts_with($problem, 'duplicate archive member:')
				|| str_starts_with($problem, 'packaged source:')
				|| str_starts_with($problem, 'packaged bundle differs:')
				|| str_starts_with($problem, 'missing packaged bundle:'),
		));
		sort($asset, SORT_STRING);
		return $asset;
	}

	/**
	 * A tree whose admin manifest builds one known bundle.
	 *
	 * @return string absolute path
	 */
	private function appWithAdminBundle(): string {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/admin.manifest", "admin/a.js\n");
		return $dir;
	}

	/**
	 * One regular-file entry of a ustar archive, header and body, with a valid checksum.
	 *
	 * @param string $name entry name, at most 100 bytes
	 * @param string $content file content
	 * @return string the 512-byte header followed by the padded body
	 */
	private static function ustar(string $name, string $content): string {
		$header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
			. sprintf('%011o', strlen($content)) . "\0" . sprintf('%011o', 0) . "\0" . str_repeat(' ', 8) . '0' . str_repeat("\0", 100) . "ustar\0" . '00';
		$header = str_pad($header, 512, "\0");
		$header = substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
		return $header . str_pad($content, (int)ceil(strlen($content) / 512) * 512, "\0");
	}

	/**
	 * An empty app directory with the four source folders and js/.
	 *
	 * @return string absolute path
	 */
	private function tree(): string {
		$dir = sys_get_temp_dir() . '/mcp-assets-' . bin2hex(random_bytes(4));
		mkdir($dir . '/src/admin', 0777, true);
		mkdir($dir . '/src/settings', 0777, true);
		mkdir($dir . '/src/shared', 0777, true);
		mkdir($dir . '/js', 0777, true);
		$this->dirs[] = $dir;
		return $dir;
	}
}
