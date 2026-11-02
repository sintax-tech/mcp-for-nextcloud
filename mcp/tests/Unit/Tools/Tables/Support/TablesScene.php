<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Modules\Tables\TablesLinks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The scene the Tables tests share, the same the integration cell seeds:
 * - alice owns table 1 "Budget" (text 11, number 12, selection 13) and table 3 "Archive" (archived);
 * - view 21 "Approved" of table 1 shows columns 11 and 12 (12 read-only) and is shared with bob;
 * - table 1 is shared with carol, read only, and with frank, with manage;
 * - view 22 "Summary" of table 1 shows column 11 and is in an application (context) erin belongs to;
 * - dave has nothing.
 */
final class TablesScene {
	/** Base of the links the scene's URL generator builds. */
	public const BASE = 'https://cloud.example/index.php/apps/tables/';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The Tables app with the scene.
	 *
	 * @return FakeTablesApp the app
	 */
	public static function app(): FakeTablesApp {
		return (new FakeTablesApp())
			->withTable(1, 'alice', 'Budget', ['emoji' => '💰', 'description' => "Team budget\u{202E}"])
			->withTable(3, 'alice', 'Archive', ['archived' => true])
			->withColumn(['id' => 11, 'tableId' => 1, 'title' => 'Item', 'type' => 'text', 'subtype' => 'line', 'description' => '', 'mandatory' => true,
				'createdBy' => 'alice', 'lastEditBy' => 'alice', 'textDefault' => '', 'textAllowedPattern' => null, 'textMaxLength' => 200, 'textUnique' => false,
				'numberMin' => null, 'selectionOptions' => []])
			->withColumn(['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => '', 'description' => 'In euros', 'mandatory' => false,
				'createdBy' => 'alice', 'numberDefault' => 0, 'numberMin' => 0, 'numberMax' => 100000, 'numberDecimals' => 2, 'numberPrefix' => '€', 'numberSuffix' => '',
				'textDefault' => 'unused'])
			->withColumn(['id' => 13, 'tableId' => 1, 'title' => 'Secret status', 'type' => 'selection', 'subtype' => '', 'description' => '', 'mandatory' => false,
				'createdBy' => 'alice', 'selectionOptions' => [['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => "Closed\u{0007}"]], 'selectionDefault' => '1'])
			->withView(21, 1, 'Approved', [11, 12], [12 => ['readonly' => true, 'mandatory' => false]])
			->withView(22, 1, 'Summary', [11])
			->sharingView(21, 'bob')
			->sharingTable(1, 'carol')
			->sharingTable(1, 'frank', true)
			->withContext('erin', [['node_id' => 22, 'node_type' => 1, 'permissions' => 1]]);
	}

	/**
	 * The links of the scene.
	 *
	 * @param TestCase $test the running test
	 * @return TablesLinks the links
	 */
	public static function links(TestCase $test): TablesLinks {
		$urls = (\Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class))(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route): string => $route === 'tables.page.index' ? self::BASE : 'wrong-route');
		return new TablesLinks($urls);
	}
}
