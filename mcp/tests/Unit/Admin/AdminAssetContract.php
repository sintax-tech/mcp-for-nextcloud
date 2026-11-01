<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Admin;
use PHPUnit\Framework\Assert;
/**
 * Static admin asset registration contract: the admin page loads one script, js/mcp-admin.js, built from
 * src/admin.manifest, and the personal page loads one script, js/mcp-personal.js, built from src/settings.manifest.
 */
final class AdminAssetContract extends Assert {
	/** The 13 scripts the admin page loaded one by one up to 1.2.5; their sources now live in src/ and ship only inside js/mcp-admin.js. */
	private const BUNDLED = ['admin-picker', 'admin-levels', 'admin-grants', 'admin-level-popover', 'admin-exceptions', 'admin-row-actions', 'admin-overrides', 'admin-groups', 'admin-scope', 'admin-inspector', 'admin-tabs', 'admin-file-write-policy'];

	/**
	 * Verifies asset registration, the fragment order of the admin bundle and the actual files.
	 *
	 * @return void
	 */
	public static function verify(): void {
		$app = dirname(__DIR__, 3);
		$template = (string)file_get_contents($app . '/templates/admin.php');
		preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $template, $matches, PREG_SET_ORDER);
		self::assertSame([['Script', 'mcp-admin'], ['Style', 'admin']], array_map(static fn (array $match): array => [$match[1], $match[2]], $matches));
		// Issue #21: a hosting layer never answered a small deferred script, and the page waited until it timed out.
		// These helpers were merged into scripts the page already loads, and since RE-11 the page loads one bundle.
		foreach (['admin-level-names', 'admin-polls-grants', 'admin-mail-grants', 'admin-backups', ...self::BUNDLED] as $removed) {
			self::assertStringNotContainsString("'$removed'", $template);
			self::assertFileDoesNotExist($app . "/js/$removed.js");
		}
		$personal = (string)file_get_contents($app . '/templates/personal.php');
		self::assertStringNotContainsString("'personal-scope'", $personal);
		self::assertFileDoesNotExist($app . '/js/personal-scope.js');
		self::assertStringContainsString("addScript('mcp', 'mcp-personal')", $personal);
		self::assertFileDoesNotExist($app . '/js/connections.js');
		preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $personal, $personalMatches, PREG_SET_ORDER);
		self::assertCount(2, $personalMatches);
		self::verifyOrder(self::fragments($app));
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

	/**
	 * The fragments of the admin bundle, in manifest order.
	 *
	 * @param string $app the app folder
	 * @return list<string> paths relative to src/
	 */
	private static function fragments(string $app): array {
		$lines = file($app . '/src/admin.manifest', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
	}

	/**
	 * The levels mapping is read by the matrix, so it comes before it; the level popover, the exception notices and the
	 * row actions use the matrix, so they come after it.
	 *
	 * @param list<string> $fragments paths relative to src/, in manifest order
	 * @return void
	 */
	private static function verifyOrder(array $fragments): void {
		$at = static function (string $path) use ($fragments): int {
			$index = array_search($path, $fragments, true);
			self::assertIsInt($index, "$path is in src/admin.manifest");
			return $index;
		};
		$grants = $at('admin/matrix/start.js');
		self::assertLessThan($grants, $at('admin/admin-levels.js'));
		foreach (['admin/level-popover/start.js', 'admin/admin-exceptions.js', 'admin/admin-row-actions.js'] as $after) {
			self::assertGreaterThan($grants, $at($after), $after);
		}
	}
}
