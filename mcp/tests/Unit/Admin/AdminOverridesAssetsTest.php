<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** T17 (C2): the override reset UI ships complete translations and keeps its boundary with the shared matrix script. */
final class AdminOverridesAssetsTest extends TestCase {
	private static function app(): string {
		return dirname(__DIR__, 3);
	}

	/** @return list<string> translation keys of the literal t()/n() calls of a script, plural keys in registry form */
	private static function keys(string $source): array {
		preg_match_all("/(?<![A-Za-z0-9_\$.])t\\('mcp',\\s*'((?:[^'\\\\]|\\\\.)*)'/s", $source, $singular);
		preg_match_all("/(?<![A-Za-z0-9_\$.])n\\('mcp',\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*'((?:[^'\\\\]|\\\\.)*)'/s", $source, $plural);
		$keys = array_map(static fn (string $text): string => stripcslashes($text), $singular[1]);
		foreach ($plural[1] as $i => $one) {
			$keys[] = '_' . stripcslashes($one) . '_::_' . stripcslashes($plural[2][$i]) . '_';
		}
		return array_values(array_unique($keys));
	}

	public function testOverrideStringsAreCompleteInBothFormatsWithTheirPlaceholders(): void {
		$app = self::app();
		$keys = self::keys((string)AdminSources::readPath($app . '/src/admin/admin-overrides.js'));
		// The one string the shared matrix script gained for the undo of a refused (503) receipt.
		$keys[] = 'The change could not be undone because what these users inherit could not be read. Nothing was changed. Try again.';
		$this->assertGreaterThan(25, count($keys));
		foreach (['pt_BR', 'es'] as $lang) {
			$translations = json_decode((string)file_get_contents("$app/l10n/$lang.json"), true, flags: JSON_THROW_ON_ERROR)['translations'];
			$browser = (string)file_get_contents("$app/l10n/$lang.js");
			foreach ($keys as $key) {
				$this->assertArrayHasKey($key, $translations, "$lang: $key");
				$forms = is_array($translations[$key]) ? $translations[$key] : [$translations[$key]];
				$this->assertCount(str_starts_with($key, '_') && str_contains($key, '_::_') ? 3 : 1, $forms, "$lang: $key");
				preg_match_all('/\{\w+\}|%n/', $key, $expected);
				foreach ($forms as $form) {
					preg_match_all('/\{\w+\}|%n/', $form, $actual);
					$this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), "$lang: $key placeholders");
				}
				$encode = static fn (string $text): string => json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				$value = is_array($translations[$key]) ? '[' . implode(', ', array_map($encode, $translations[$key])) . ']' : $encode($translations[$key]);
				$this->assertStringContainsString($encode($key) . ' : ' . $value, $browser, "$lang.js: $key");
			}
		}
	}

	/** F2: the page cannot know what the groups would give, so it never promises a disconnect it cannot know. */
	public function testThePreviewNeverPromisesADisconnect(): void {
		$source = (string)AdminSources::readPath(self::app() . '/src/admin/admin-overrides.js');
		$this->assertDoesNotMatchRegularExpression('/will (be )?(signed out|disconnected|lose access|lose their)/i', $source);
		$this->assertStringContainsString('they may be signed out of their MCP clients', $source);
		$this->assertStringContainsString('undoing this does not sign them back in', $source);
	}

	public function testTheSharedScriptOnlyExposesAndCallsAndTheOverridesScriptNeverTouchesItsState(): void {
		$app = self::app();
		$manifest = file($app . '/src/admin.manifest', FILE_IGNORE_NEW_LINES);
		$this->assertLessThan(array_search('admin/overrides/start.js', $manifest, true), array_search('admin/matrix/start.js', $manifest, true),
			'the matrix script exposes window.OCA.Mcp.Admin.matrix before the overrides script reads it');
		$grants = (string)AdminSources::readPath($app . '/src/admin/admin-grants.js');
		foreach (['window.OCA.Mcp.Admin.matrix = {', 'window.OCA.Mcp.Admin.overrides?.decorateRow(row, user)', 'window.OCA.Mcp.Admin.overrides?.decorateCell(', "dispatchEvent(new Event('mcp-matrix-rendered'))"] as $needle) {
			$this->assertStringContainsString($needle, $grants);
		}
		$overrides = (string)AdminSources::readPath($app . '/src/admin/admin-overrides.js');
		$this->assertStringNotContainsString('state.data', $overrides, 'only through window.OCA.Mcp.Admin.matrix');
		$this->assertStringNotContainsString("'/api/grants/' +", $overrides, 'a reset is a command, never a per-user write');
		$this->assertStringContainsString("state: 'inherit'", $overrides);
		$this->assertStringNotContainsString('granted', $overrides, 'the form with a boolean is never mixed with the triestate command');
	}

	/** The preview is read only: one POST, to the route that has no write and answers 403 before it resolves anything. */
	public function testThePreviewRouteIsTheOneThePageReadsAndItsControllerGuardsFirst(): void {
		$app = self::app();
		$this->assertStringContainsString("'/api/grants/commands/preview'", (string)AdminSources::readPath($app . '/src/admin/admin-overrides.js'));
		$this->assertStringContainsString("'url' => '/api/grants/commands/preview', 'verb' => 'POST'", (string)file_get_contents($app . '/appinfo/routes.php'));
		$controller = (string)file_get_contents($app . '/lib/Controller/OverridePreviewController.php');
		$this->assertLessThan(strpos($controller, '$this->preview->preview('), strpos($controller, 'isAdmin('), 'the administrator check precedes the resolution');
		$this->assertStringNotContainsString('NoAdminRequired', $controller);
		$service = (string)file_get_contents($app . '/lib/Access/Grants/OverridePreview.php');
		foreach (['setUserValue', 'deleteUserValue', 'applyCells', 'runInternal', 'beginTransaction'] as $write) {
			$this->assertStringNotContainsString($write, $service, 'a preview never writes');
		}
		$this->assertStringContainsString('admin counting only, not an access decision', $service);
	}

	/** I2/O2: two fixed live regions and a delayed write, in the groups editor and in the reset toolbar. */
	public function testTheLiveRegionsAreFixedAndWrittenAfterTheyAreCleared(): void {
		$app = self::app();
		foreach (['admin-groups', 'admin-overrides'] as $script) {
			$source = (string)AdminSources::readPath("$app/src/admin/$script.js");
			$this->assertStringContainsString('clearTimeout(sayTimer)', $source, $script);
			$this->assertMatchesRegularExpression('/sayTimer = setTimeout\(\(\) => \{\s*region\.textContent = message/', $source, $script);
			$this->assertStringNotContainsString("setAttribute('role', error", $source, "$script: the role never changes at run time");
		}
		$this->assertStringContainsString('role="status" aria-live="polite"', (string)file_get_contents($app . '/templates/admin.php'));
		$overrides = (string)AdminSources::readPath($app . '/src/admin/admin-overrides.js');
		$this->assertStringContainsString("statusLine.setAttribute('role', 'status')", $overrides);
		$this->assertStringContainsString("alertLine.setAttribute('role', 'alert')", $overrides);
	}
}
