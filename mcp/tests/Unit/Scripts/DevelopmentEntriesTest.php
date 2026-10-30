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
}
