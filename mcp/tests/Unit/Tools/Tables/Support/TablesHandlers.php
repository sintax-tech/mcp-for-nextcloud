<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Tools\Tables\Columns\ColumnLookup;
use OCA\Mcp\Tools\Tables\Discovery\DiscoveryShapes;
use OCA\Mcp\Tools\Tables\Discovery\TablesDiscovery;
use OCA\Mcp\Tools\Tables\Discovery\ViewsDiscovery;
use OCA\Mcp\Tools\Tables\Handler\ListColumnsHandler;
use OCA\Mcp\Tools\Tables\Handler\ListTablesHandler;
use OCA\Mcp\Tools\Tables\Handler\ListViewsHandler;
use OCA\Mcp\Tools\Tables\TablesModule;
use PHPUnit\Framework\TestCase;

/**
 * The Tables handlers and module wired over a fake app, as the container wires them, and the decoded answer of a call.
 */
final class TablesHandlers {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The module over the app.
	 *
	 * @param TestCase $test the running test
	 * @param FakeTablesApp $app the app
	 * @param string|null $sessionUser UID the session holds for the rows and row write tools, null for no session user
	 * @return TablesModule the module
	 */
	public static function module(TestCase $test, FakeTablesApp $app, ?string $sessionUser = 'alice'): TablesModule {
		$links = TablesScene::links($test);
		$shapes = new DiscoveryShapes($links);
		return new TablesModule(
			new ListTablesHandler(new TablesDiscovery($app), $shapes),
			new ListViewsHandler(new TablesDiscovery($app), new ViewsDiscovery($app), $shapes),
			new ListColumnsHandler(new ColumnLookup($app), $links),
			TablesRowsWiring::handler($test, $app, $sessionUser),
			...TablesWriteWiring::handlers($test, $app, $sessionUser),
		);
	}

	/**
	 * The decoded answer of a tool call.
	 *
	 * @param TestCase $test the running test
	 * @param FakeTablesApp $app the app
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments arguments
	 * @param string $uid the user
	 * @return array<string, mixed> the answer
	 */
	public static function answer(TestCase $test, FakeTablesApp $app, string $tool, array $arguments, string $uid): array {
		$result = self::module($test, $app)->call($tool, $arguments, $uid);
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
