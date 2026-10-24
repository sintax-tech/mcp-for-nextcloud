<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Admin;
use PHPUnit\Framework\Assert;
/** Static admin asset registration contract. */
final class AdminAssetContract extends Assert {
	/** @return void verify asset registration, order and actual files */
	public static function verify(): void {
		$app = dirname(__DIR__, 3);
		$template = (string)file_get_contents($app . '/templates/admin.php');
		preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $template, $matches, PREG_SET_ORDER);
		self::assertCount(15, $matches);
		// The levels mapping is read by the matrix, so it loads before it; the level popover uses the matrix, so after it.
		self::assertLessThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-levels')"));
		self::assertGreaterThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-level-popover')"));
		self::assertGreaterThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-exceptions')"));
		self::assertGreaterThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-row-actions')"));
		foreach ($matches as [, $kind, $name]) {
			$file = $kind === 'Script' ? "$app/js/$name.js" : "$app/css/$name.css";
			self::assertFileExists($file);
		}
		self::assertFileDoesNotExist($app . '/js/admin-user-picker.js');
		self::assertFileDoesNotExist($app . '/lib/Controller/UsersController.php');
	}
}
