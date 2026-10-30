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
		// Polls presets and Mail warnings live in admin-levels.js, which still loads before the matrix.
		// Their old order is checked by tests/Frontend/admin-mail-levels.test.cjs and the Polls frontend tests.
		self::assertCount(14, $matches);
		// Issue #21: a hosting layer never answered a small deferred script, and the page waited until it timed out.
		// These helpers now live in a script the page already loads. The separate files must not come back.
		foreach (['admin-level-names', 'admin-polls-grants', 'admin-mail-grants', 'admin-backups'] as $removed) {
			self::assertStringNotContainsString("'$removed'", $template);
			self::assertFileDoesNotExist($app . "/js/$removed.js");
		}
		$personal = (string)file_get_contents($app . '/templates/personal.php');
		self::assertStringNotContainsString("'personal-scope'", $personal);
		self::assertFileDoesNotExist($app . '/js/personal-scope.js');
		self::assertStringContainsString("addScript('mcp', 'connections')", $personal);
		preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $personal, $personalMatches, PREG_SET_ORDER);
		self::assertCount(2, $personalMatches);
		// The levels mapping is read by the matrix, so it loads before it; the level popover uses the matrix, so after it.
		self::assertLessThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-levels')"));
		self::assertGreaterThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-level-popover')"));
		self::assertGreaterThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-exceptions')"));
		self::assertGreaterThan(strpos($template, "addScript('mcp', 'admin-grants')"), strpos($template, "addScript('mcp', 'admin-row-actions')"));
		foreach (array_merge($matches, $personalMatches) as [, $kind, $name]) {
			$file = $kind === 'Script' ? "$app/js/$name.js" : "$app/css/$name.css";
			self::assertFileExists($file);
			// A script under 5 KB, loaded on its own with defer, is the shape that stuck the admin page in #21.
			if ($kind === 'Script') {
				self::assertGreaterThanOrEqual(5000, filesize($file), $name);
			}
		}
		self::assertFileDoesNotExist($app . '/js/admin-user-picker.js');
		self::assertFileDoesNotExist($app . '/lib/Controller/UsersController.php');
	}
}
