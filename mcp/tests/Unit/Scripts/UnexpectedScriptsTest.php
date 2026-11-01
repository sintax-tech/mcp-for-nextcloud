<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use OCA\Mcp\Scripts\PackagedAssets;
use OCA\Mcp\Scripts\UnexpectedScripts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/AssetBuilder.php';
require_once __DIR__ . '/../../../scripts/PackagedAssets.php';

/**
 * Covers the refusal of any script under `mcp/js/` other than the two generated bundles.
 */
final class UnexpectedScriptsTest extends TestCase {
	/**
	 * The two bundles are allowed; any other script, at any depth or case, is refused.
	 *
	 * @return void
	 */
	public function testOnlyTheTwoBundlesAreAllowedUnderJs(): void {
		$this->assertNull(UnexpectedScripts::problem('mcp/js/mcp-admin.js'));
		$this->assertNull(UnexpectedScripts::problem('mcp/js/mcp-personal.js'));
		foreach (['mcp/js/connections.js', 'mcp/js/admin-grants.js', 'mcp/js/sub/mcp-admin.js', 'mcp/js/MCP-ADMIN.JS', 'mcp/js/mcp-admin.js.js'] as $name) {
			$this->assertSame("unexpected script: $name", UnexpectedScripts::problem($name), $name);
		}
	}

	/**
	 * Other file types and other folders are not this class's concern.
	 *
	 * @return void
	 */
	public function testOtherFilesAreIgnored(): void {
		foreach (['mcp/js/mcp-admin.js.map', 'mcp/js/README.md', 'mcp/css/admin.css', 'mcp/lib/Page.js'] as $name) {
			$this->assertNull(UnexpectedScripts::problem($name), $name);
		}
	}

	/**
	 * The archive check reports an extra script, however its name is written in the archive, and a link too.
	 *
	 * @return void
	 */
	public function testThePackageCheckRefusesAnExtraScript(): void {
		$dir = sys_get_temp_dir() . '/mcp-unexpected-' . bin2hex(random_bytes(4));
		mkdir($dir);
		try {
			foreach (['mcp/js/connections.js', 'mcp/js/./connections.js', 'mcp//js/connections.js'] as $written) {
				$problems = PackagedAssets::problems($dir, [['name' => $written, 'dir' => false, 'pax' => false, 'body' => 'x']]);
				$this->assertContains('unexpected script: mcp/js/connections.js', $problems, $written);
			}
			$link = PackagedAssets::problems($dir, [['name' => 'mcp/js/connections.js', 'dir' => false, 'pax' => false, 'link' => true]]);
			$this->assertContains('unexpected script: mcp/js/connections.js', $link);
			$this->assertContains('archive link: mcp/js/connections.js', $link);
			$this->assertSame([], PackagedAssets::problems($dir, [['name' => 'mcp/js', 'dir' => true, 'pax' => false]]));
		} finally {
			rmdir($dir);
		}
	}
}
