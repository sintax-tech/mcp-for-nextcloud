<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Inventory\AssetRules;

/** Controls exercise the same checker called by the existing AssetBundleTest, without moving that test. */
final class AssetControlsTest extends ArchitectureCase {
	public function testScriptAndRequiredTemplateMutantsFail(): void {
		$scripts = ['js/mcp-admin.js', 'js/mcp-personal.js'];
		self::assertSame([], AssetRules::scripts($scripts));
		self::assertNotSame([], AssetRules::scripts([...$scripts, 'js/nested/extra.js']));
		$templates = ['admin.php' => "Util::addScript('mcp', 'mcp-admin');", 'personal.php' => "Util::addScript('mcp', 'mcp-personal');"];
		self::assertSame([], AssetRules::templates($templates));
		foreach (['admin.php', 'personal.php'] as $name) {
			$missing = $templates; unset($missing[$name]);
			self::assertNotSame([], AssetRules::templates($missing));
			$duplicate = $templates; $duplicate[$name] .= $duplicate[$name];
			self::assertNotSame([], AssetRules::templates($duplicate));
			$wrong = $templates; $wrong[$name] = "Util::addScript('mcp', 'wrong');";
			self::assertNotSame([], AssetRules::templates($wrong));
		}
	}
}
