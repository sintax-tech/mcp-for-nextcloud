<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use FilesystemIterator;
use OCA\Mcp\Scripts\AssetBuilder;
use OCA\Mcp\Scripts\PackageCheck;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/../../../scripts/check_package.php';

/**
 * Round 2 of the RE-10 review: a `}` inside a regex or a string must not hide module syntax, and a tar name
 * with `.` or `//` is the path tar installs.
 */
final class AssetCanonicalTest extends TestCase {
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

	/** A `}` inside a regex, a string, a nested template or a comment does not end the interpolation early. */
	public function testABraceInsideARegexOrStringDoesNotHideModuleSyntax(): void {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/regex.js", 'const x = `${ /}/.test("}") ? require("x") : "" }`;' . "\n");
		file_put_contents("$dir/src/admin/string.js", 'const x = `${ "}" ? require("x") : "" }`;' . "\n");
		file_put_contents("$dir/src/admin/nested.js", 'const x = `${ `${"}"}` ? require("x") : "" }`;' . "\n");
		file_put_contents("$dir/src/admin/comment.js", 'const x = `${ /* } */ require("x") }`;' . "\n");
		file_put_contents("$dir/src/admin.manifest", "admin/regex.js\nadmin/string.js\nadmin/nested.js\nadmin/comment.js\n");
		$this->assertSame([
			'forbidden module syntax: require in admin/comment.js',
			'forbidden module syntax: require in admin/nested.js',
			'forbidden module syntax: require in admin/regex.js',
			'forbidden module syntax: require in admin/string.js',
		], AssetBuilder::build($dir));
		$this->assertFileDoesNotExist("$dir/js/mcp-admin.js");

		$clean = $this->tree();
		$policy = 'const a = `${ "require}" }`;' . "\n";
		$policy .= 'const b = `${ /* require */ 1 }`;' . "\n";
		$policy .= 'const c = `${ 1 / 2 }`;' . "\n";
		$policy .= 'const d = `require stays literal`;' . "\n";
		file_put_contents("$clean/src/admin/policy.js", $policy);
		file_put_contents("$clean/src/admin.manifest", "admin/policy.js\n");
		$this->assertSame([], AssetBuilder::build($clean));
	}

	/** `mcp/js/./mcp-admin.js` is the admin bundle, and `mcp/./src/` is packaged source. */
	public function testADotSegmentIsTheSameArchiveMember(): void {
		$dir = $this->appWithAdminBundle();
		$good = "/* admin/a.js */\nvar a = 1\n";
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(
			self::ustar('mcp/js/mcp-admin.js', $good)
			. self::ustar('mcp/js/./mcp-admin.js', "/* stale */\n")
			. self::ustar('mcp/./src/admin/leaked.js', "var leaked = 1\n")
			. str_repeat("\0", 1024),
		));
		$this->assertSame([
			'duplicate archive member: mcp/js/mcp-admin.js',
			'packaged bundle differs: mcp/js/mcp-admin.js',
			'packaged source: mcp/src/admin/leaked.js',
		], $this->packaged($dir, $tar));
	}

	/** `//` collapses to the bundle path. `..` and a leading slash are refused and not resolved. */
	public function testSlashesAndParentSegmentsAreCanonicalOrRefused(): void {
		$dir = $this->appWithAdminBundle();
		$good = "/* admin/a.js */\nvar a = 1\n";
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(self::ustar('mcp//js/mcp-admin.js', $good) . str_repeat("\0", 1024)));
		$this->assertSame([], $this->packaged($dir, $tar));

		file_put_contents($tar, gzencode(
			self::ustar('mcp/js/../src/admin/leaked.js', "var leaked = 1\n")
			. self::ustar('/mcp/js/mcp-admin.js', $good)
			. str_repeat("\0", 1024),
		));
		$this->assertSame([
			'missing packaged bundle: mcp/js/mcp-admin.js',
			'unsafe archive member: /mcp/js/mcp-admin.js',
			'unsafe archive member: mcp/js/../src/admin/leaked.js',
		], $this->packaged($dir, $tar));
	}

	/** A symlink whose canonical name is the bundle is not the bundle file tar would leave behind. */
	public function testALinkAtTheBundlePathIsRefused(): void {
		$dir = $this->appWithAdminBundle();
		$tar = "$dir/package.tar.gz";
		file_put_contents($tar, gzencode(
			self::ustar('mcp/js/./mcp-admin.js', '', '2', 'stale.js')
			. str_repeat("\0", 1024),
		));
		$this->assertSame([
			'archive link: mcp/js/mcp-admin.js',
			'packaged bundle differs: mcp/js/mcp-admin.js',
		], $this->packaged($dir, $tar));
	}

	/**
	 * Archive problems about names, sources and bundle bytes, sorted.
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
				|| str_starts_with($problem, 'missing packaged bundle:')
				|| str_starts_with($problem, 'unsafe archive member:')
				|| str_starts_with($problem, 'archive link:'),
		));
		sort($asset, SORT_STRING);
		return $asset;
	}

	/**
	 * @return string absolute path
	 */
	private function appWithAdminBundle(): string {
		$dir = $this->tree();
		file_put_contents("$dir/src/admin/a.js", "var a = 1\n");
		file_put_contents("$dir/src/admin.manifest", "admin/a.js\n");
		return $dir;
	}

	/**
	 * One ustar entry. `$type` `2` is a symlink whose target is `$link` and whose body is empty.
	 *
	 * @param string $name entry name, at most 100 bytes
	 * @param string $content file content
	 * @param string $type typeflag
	 * @param string $link link target, at most 100 bytes
	 * @return string the header followed by the padded body
	 */
	private static function ustar(string $name, string $content, string $type = '0', string $link = ''): string {
		$header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
			. sprintf('%011o', strlen($content)) . "\0" . sprintf('%011o', 0) . "\0" . str_repeat(' ', 8) . $type
			. str_pad($link, 100, "\0") . "ustar\0" . '00';
		$header = str_pad($header, 512, "\0");
		$header = substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
		return $header . str_pad($content, (int)ceil(strlen($content) / 512) * 512, "\0");
	}

	/**
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
