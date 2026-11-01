<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use FilesystemIterator;
use OCA\Mcp\Scripts\AssetBuilder;
use OCA\Mcp\Scripts\BundleText;
use OCA\Mcp\Scripts\PackageCheck;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/../../../scripts/check_package.php';

/**
 * Covers the PHP asset builder: refused sources, the exact bundle bytes, and an interrupted write.
 */
final class AssetBuilderTest extends TestCase {
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

	/** A script under src/ that no manifest names fails the build and does not replace a previous bundle. */
	public function testOrphanSourceFailsAndLeavesTheBundleUntouched(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/extra.js", "var extra = 1\n");
		file_put_contents("$dir/js/mcp-admin.js", 'OLD');
		$this->assertSame(['orphan source: admin/extra.js'], AssetBuilder::build($dir));
		$this->assertSame('OLD', file_get_contents("$dir/js/mcp-admin.js"));
	}

	/** A manifest line that does not name a file fails and writes nothing. */
	public function testMissingEntryFails(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin.manifest", "admin/missing.js\n");
		$this->assertSame(['missing entry: admin/missing.js'], AssetBuilder::build($dir));
		$this->assertFileDoesNotExist("$dir/js/mcp-admin.js");
	}

	/** The same path twice in one manifest is a duplicate, even when the file exists. */
	public function testDuplicateEntryFails(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/shared/s.js", "var s = 1\n");
		file_put_contents("$dir/src/admin.manifest", "shared/s.js\nshared/s.js\n");
		$this->assertSame(['duplicate entry: shared/s.js in src/admin.manifest'], AssetBuilder::build($dir));
		$this->assertFileDoesNotExist("$dir/js/mcp-admin.js");
	}

	/** A path that climbs out of src/, an absolute path and a symlink that escapes are refused. */
	public function testPathOutsideSrcFails(): void {
		$dir = $this->tree();
		file_put_contents("$dir/outside.js", "var out = 1\n");
		file_put_contents("$dir/src/admin.manifest", "../outside.js\n/tmp/x.js\nadmin/link.js\n");
		symlink('../../outside.js', "$dir/src/admin/link.js");
		$this->assertSame([
			'path outside src/: ../outside.js',
			'path outside src/: /tmp/x.js',
			'path outside src/: admin/link.js',
		], AssetBuilder::build($dir));
	}

	/** An admin fragment may not be listed in the personal manifest. shared/ may be listed in both. */
	public function testAdminFragmentIsRefusedInThePersonalManifest(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/settings.manifest", "admin/a.js\n");
		$this->assertSame(['admin source in personal manifest: admin/a.js'], AssetBuilder::build($dir));
		$this->assertFileDoesNotExist("$dir/js/mcp-personal.js");
	}

	/** import, export and require are refused, including inside a string. module.exports passes. */
	public function testModuleSyntaxIsRefusedAndModuleExportsPasses(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/import.js", "import x from './x.js'\n");
		file_put_contents("$dir/src/admin/export.js", "export const x = 1\n");
		file_put_contents("$dir/src/admin/require.js", "const x = require('./x.js')\n");
		file_put_contents("$dir/src/admin/quoted.js", "const label = \"import export require\"\n");
		file_put_contents("$dir/src/admin.manifest", "admin/import.js\nadmin/export.js\nadmin/require.js\nadmin/quoted.js\n");
		$this->assertSame([
			'forbidden module syntax: export in admin/export.js:1',
			'forbidden module syntax: import in admin/import.js:1',
			'forbidden module syntax: import in admin/quoted.js:1',
			'forbidden module syntax: require in admin/require.js:1',
		], AssetBuilder::build($dir));

		$clean = $this->tree();
		$policy = "const label = \"importantValue exported\"\n";
		$policy .= "if (typeof module !== 'undefined' && module.exports) module.exports = { extensions: 1 }\n";
		file_put_contents("$clean/src/admin/policy.js", $policy);
		file_put_contents("$clean/src/admin.manifest", "admin/policy.js\n");
		$this->assertSame([], AssetBuilder::build($clean));
	}

	/** One shared fragment is copied into each bundle once, in the order the manifest lists. */
	public function testSharedSourceIsInBothBundlesOnce(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/shared/s.js", "var s = 1\n");
		file_put_contents("$dir/src/admin.manifest", "shared/s.js\nadmin/a.js\n");
		file_put_contents("$dir/src/settings.manifest", "shared/s.js\n");
		$this->assertSame([], AssetBuilder::build($dir));
		$admin = (string)file_get_contents("$dir/js/mcp-admin.js");
		$personal = (string)file_get_contents("$dir/js/mcp-personal.js");
		$this->assertSame(1, substr_count($admin, '/* shared/s.js */'));
		$this->assertSame(1, substr_count($personal, '/* shared/s.js */'));
		$this->assertLessThan(strpos($admin, '/* admin/a.js */'), strpos($admin, '/* shared/s.js */'));
	}

	/** The header is the manifest path and the separator between fragments is semicolon plus newline. */
	public function testTheSeparatorAndHeaderAreExactAndDeterministic(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/b.js", "var b = 1\n");
		file_put_contents("$dir/src/admin/a.js", "var a = 2\n");
		file_put_contents("$dir/src/admin.manifest", "# comment\n\nadmin/b.js\nadmin/a.js\n");
		$expected = "/* admin/b.js */\nvar b = 1\n;\n/* admin/a.js */\nvar a = 2\n";
		$this->assertSame($expected, BundleText::render($dir, ['admin/b.js', 'admin/a.js']));
		$this->assertSame([], AssetBuilder::build($dir));
		$this->assertSame($expected, file_get_contents("$dir/js/mcp-admin.js"));
		$this->assertSame([], AssetBuilder::build($dir));
		$this->assertSame($expected, file_get_contents("$dir/js/mcp-admin.js"));
	}

	/** A failure after the first temporary file is staged removes it and leaves both bundles unchanged. */
	public function testAnInterruptedBuildLeavesNoPartialBundle(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/settings/p.js", "var p = 1\n");
		file_put_contents("$dir/src/admin.manifest", "admin/a.js\n");
		file_put_contents("$dir/src/settings.manifest", "settings/p.js\n");
		file_put_contents("$dir/js/mcp-admin.js", 'OLD');
		file_put_contents("$dir/js/mcp-personal.js", 'OLD2');
		$this->assertSame(['build interrupted'], AssetBuilder::build($dir, null, 1));
		$this->assertSame('OLD', file_get_contents("$dir/js/mcp-admin.js"));
		$this->assertSame('OLD2', file_get_contents("$dir/js/mcp-personal.js"));
		$this->assertSame([], glob($dir . '/js/.*tmp') ?: []);
	}

	/** The command line prints problems on stderr and does not write a bundle. */
	public function testTheCommandLineExitsWithTheProblems(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/extra.js", "var extra = 1\n");
		[$code, $stdout, $stderr] = $this->runScript($dir, []);
		$this->assertSame(1, $code);
		$this->assertSame('', $stdout);
		$this->assertSame("orphan source: admin/extra.js\n", $stderr);
		$this->assertFileDoesNotExist("$dir/js/mcp-admin.js");

		[$code, $stdout, $stderr] = $this->runScript($dir, ['--help']);
		$this->assertSame([2, '', "usage: php scripts/build_assets.php\n"], [$code, $stdout, $stderr]);
	}

	/** check_package.php reports an orphan and a missing entry for the tree it is run in. */
	public function testPackageCheckReportsAnOrphanAndAMissingEntry(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/extra.js", "var extra = 1\n");
		file_put_contents("$dir/src/settings.manifest", "settings/missing.js\n");
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(self::ustar('mcp/appinfo/info.xml', '<info/>') . str_repeat("\0", 1024)));
		$cwd = getcwd();
		chdir($dir);
		try {
			$problems = PackageCheck::problems($tar, []);
		} finally {
			chdir($cwd === false ? $dir : $cwd);
		}
		$asset = array_values(array_filter(
			$problems,
			static fn (string $problem): bool => str_starts_with($problem, 'orphan source:') || str_starts_with($problem, 'missing entry:'),
		));
		$this->assertSame(['missing entry: settings/missing.js', 'orphan source: admin/extra.js'], $asset);
	}

	/** A manifest that is not admin.manifest or settings.manifest does not cover a source. */
	public function testAnUnknownManifestDoesNotCoverASource(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/other.manifest", "admin/a.js\n");
		$this->assertSame(['orphan source: admin/a.js'], AssetBuilder::problems($dir));
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
		file_put_contents($dir . '/src/admin.manifest', '');
		file_put_contents($dir . '/src/settings.manifest', '');
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * Runs scripts/build_assets.php with the fixture as its working directory.
	 *
	 * @param string $dir app directory
	 * @param list<string> $args arguments after the script path
	 * @return array{0: int, 1: string, 2: string} exit code, stdout and stderr
	 */
	private function runScript(string $dir, array $args): array {
		$script = realpath(__DIR__ . '/../../../scripts/build_assets.php');
		$process = proc_open([PHP_BINARY, $script === false ? '' : $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
		$this->assertIsResource($process);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
	}
}
