<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use OCA\Mcp\Scripts\DevelopmentEntries;
use OCA\Mcp\Scripts\PackageCheck;
use PharData;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/check_package.php';

/**
 * Covers the archive gate's refusal of development-only folders (`mcp/scripts/`, `mcp/tests/`).
 */
final class DevelopmentEntriesTest extends TestCase {
	/** Files and directory entries under scripts/ or tests/ are refused, sorted by name. */
	public function testScriptsAndTestsEntriesAreRefused(): void {
		$this->assertSame([
			'development-only entry: mcp/scripts',
			'development-only entry: mcp/scripts/dev/fqcn-map.json',
			'development-only entry: mcp/tests/Unit/AppTest.php',
		], DevelopmentEntries::problems([
			['mcp/tests/Unit/AppTest.php', false],
			['mcp/scripts/', true],
			['mcp/scripts/dev/fqcn-map.json', false],
		]));
	}

	/** Production paths pass, including names that only start like a forbidden folder. */
	public function testProductionEntriesPass(): void {
		$this->assertSame([], DevelopmentEntries::problems([
			['mcp', true],
			['mcp/lib/Tools/ToolRegistry.php', false],
			['mcp/scriptsx/a.php', false],
			['mcp/testsuite.txt', false],
			['mcp/vendor/smalot/pdfparser/tests/x.php', false],
		]));
	}

	/** Non-canonical names that extract under a forbidden folder are refused (review A4). */
	public function testNonCanonicalNamesAreRefused(): void {
		$this->assertSame([
			'development-only entry: mcp/./scripts/dev/probe.php',
			'development-only entry: mcp//tests/probe.php',
			'development-only entry: mcp/lib/../scripts/probe.php',
		], DevelopmentEntries::problems([
			['mcp/./scripts/dev/probe.php', false],
			['mcp//tests/probe.php', false],
			['mcp/lib/../scripts/probe.php', false],
			['mcp/./lib/ok.php', false],
			['mcp/scripts/../lib/ok.php', false],
		]));
		$this->assertSame('mcp/scripts/x', DevelopmentEntries::canonical('mcp/./lib/..//scripts/x/'));
	}

	/** A USTAR archive written byte by byte with such names fails the whole gate. */
	public function testPackageCheckRefusesNonCanonicalNamesInARealArchive(): void {
		$dir = sys_get_temp_dir() . '/mcp-development-entries-' . bin2hex(random_bytes(6));
		mkdir($dir);
		$tar = "$dir/package.tar";
		try {
			$blocks = '';
			foreach (['mcp/appinfo/info.xml', 'mcp/./scripts/dev/probe.php', 'mcp//tests/probe.php', 'mcp/scriptsx/probe.php'] as $name) {
				$blocks .= self::ustar($name, '<?php');
			}
			file_put_contents($tar, $blocks . str_repeat("\0", 1024));
			$problems = array_values(array_filter(PackageCheck::problems($tar, []), static fn (string $p): bool => str_starts_with($p, 'development-only')));
			$this->assertSame(['development-only entry: mcp/./scripts/dev/probe.php', 'development-only entry: mcp//tests/probe.php'], $problems);
		} finally {
			// Only the archive and folder this test created.
			if (is_file($tar)) {
				unlink($tar);
			}
			rmdir($dir);
		}
	}

	/** The gate applies the refusal to a real archive. */
	public function testPackageCheckRefusesAnArchiveWithDevelopmentFolders(): void {
		$dir = sys_get_temp_dir() . '/mcp-development-entries-' . bin2hex(random_bytes(6));
		mkdir($dir);
		$tar = "$dir/package.tar";
		try {
			$archive = new PharData($tar);
			$archive->addFromString('mcp/appinfo/info.xml', '<info/>');
			$archive->addFromString('mcp/scripts/dev/move_equivalence.php', '<?php');
			$archive->addFromString('mcp/tests/bootstrap.php', '<?php');
			unset($archive);
			$problems = PackageCheck::problems($tar, []);
			$this->assertContains('development-only entry: mcp/scripts/dev/move_equivalence.php', $problems);
			$this->assertContains('development-only entry: mcp/tests/bootstrap.php', $problems);
		} finally {
			// Only the archive and folder this test created.
			if (is_file($tar)) {
				unlink($tar);
			}
			rmdir($dir);
		}
	}

	/**
	 * One regular-file entry of a USTAR archive, header and body, with a valid checksum.
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
}
