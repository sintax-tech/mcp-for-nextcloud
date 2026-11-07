<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Settings\AdminPanels;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Template\ITemplate;
use OCP\Template\ITemplateManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Exact rendered markup and public native rendering contract of the five admin panels. */
final class AdminPanelRenderingTest extends TestCase {
	/** @return void every original conditional branch renders identical bytes and assets */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testPanelsPreserveTheWholeRenderedPage(): void {
		require __DIR__ . '/AdminTemplateRuntime.php';
		$app = dirname(__DIR__, 3);
		$oracle = (string)file_get_contents($app . '/tests/fixtures/admin-template-before.php');
		$this->assertSame('ff28eed00beb2c156f3c9e99a30d289220083296b3a577e200d3a9e9bd377733', hash('sha256', str_replace("dirname(__DIR__, 2) . '/templates/", "__DIR__ . '/", $oracle)));
		$manager = $this->createMock(ITemplateManager::class);
		$names = [];
		$expected = [];
		$manager->expects($this->exactly(240))->method('getTemplate')->willReturnCallback(
			function (string $appName, string $name, string $renderAs, bool $registerCall) use ($app, &$names, &$expected): ITemplate {
				$this->assertSame('mcp', $appName);
				$this->assertSame(TemplateResponse::RENDER_AS_BLANK, $renderAs);
				$this->assertFalse($registerCall);
				$names[] = $name;
				$template = $this->createMock(ITemplate::class);
				$assigned = ['cspNonce' => 'child-nonce', 'requesttoken' => ''];
				$template->expects($this->exactly(count($expected)))->method('assign')->willReturnCallback(static function (string $key, mixed $value) use (&$assigned): void { $assigned[$key] = $value; });
				$template->expects($this->once())->method('fetchPage')->willReturnCallback(function (array $parameters) use ($app, $name, &$expected, &$assigned): string {
					$this->assertSame($expected, $parameters);
					$runtime = array_merge($parameters, $assigned);
					$this->assertSame($expected, $runtime, 'native internal variables must not replace the parent context');
					return AdminTemplateRuntime::render($app . '/templates/' . $name . '.php', $runtime);
				});
				return $template;
			});
		$renderer = new AdminPanels($manager);
		for ($flags = 0; $flags < 8; $flags++) {
			foreach ([null, 7] as $count) {
				foreach ([[], [['name' => '<Deck & "cards">', 'installed' => '1.0<old>', 'required' => '2.0&new']],
					[['name' => '<Deck & "cards">', 'installed' => '1.0<old>', 'required' => '2.0&new'],
					 ['name' => 'Tables <&> "rows"', 'installed' => '0.1<&>', 'required' => '1.0"new"']]] as $unsupportedApps) {
					$expected = [
						'endpoint' => 'https://cloud.test/?a=<tag>&b="quoted"', 'version' => '<release & "version">',
						'serviceEnabled' => (bool)($flags & 1), 'ocrActive' => (bool)($flags & 2), 'logsAvailable' => (bool)($flags & 4),
						'ocrUrl' => 'https://apps.test/?a="ocr"&b=<tag>', 'logType' => '<syslog & "remote">',
						'eligibleUsers' => $count, 'connectedUsers' => $count, 'activeConnections' => 4, 'unsupportedApps' => $unsupportedApps,
						'requesttoken' => 'same-token', 'cspNonce' => 'same-nonce', 'renderAdminPanel' => [$renderer, 'render'],
					];
					\OCP\Util::$assets = [];
					$headers = headers_list();
					$before = AdminTemplateRuntime::render($app . '/tests/fixtures/admin-template-before.php', $expected);
					$assets = \OCP\Util::$assets;
					\OCP\Util::$assets = [];
					$after = AdminTemplateRuntime::render($app . '/templates/admin.php', $expected);
					$this->assertSame($before, $after);
					$this->assertSame([['script', 'mcp', 'mcp-admin'], ['style', 'mcp', 'admin']], $assets);
					$this->assertSame($assets, \OCP\Util::$assets);
					$this->assertSame($headers, headers_list());
					$this->assertSame(['admin/overview', 'admin/access', 'admin/clients', 'admin/protection', 'admin/advanced'], array_splice($names, 0));
				}
			}
		}
	}
}
