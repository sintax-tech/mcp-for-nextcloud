<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

/**
 * The scene of the row tests, the same the Tables rows cell seeds:
 * - alice owns table 1 "Events": Item (text 11), Amount (number 12), Status (single selection 13: 1 Open, 2 Closed,
 *   10 Árvore), Secret note (text 14) and Tags (multiple selection 15: 1 red, 10 Blue);
 * - view 31 "Open events" shows 11, 12, 13 and 15, filters on the hidden 14 (contains "ok"), sorts by Amount, and is
 *   shared with bob;
 * - table 1 is shared with carol to read, and with gina to create rows only;
 * - bob owns table 2 "Other" with column 21, which no rule of table 1 may name;
 * - dave has nothing.
 */
final class RowsScene {
	/** The saved filter of view 31, on the hidden column. */
	public const VIEW_FILTER = '[[{"columnId":14,"operator":"contains","value":"ok"}]]';

	/** The saved sort of view 31. */
	public const VIEW_SORT = '[{"columnId":12,"mode":"ASC"}]';

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
	 * @param string $version installed Tables version
	 * @return FakeTablesRowsApp the app
	 */
	public static function app(string $version = '2.3.2'): FakeTablesRowsApp {
		$app = new FakeTablesRowsApp();
		$app->tablesVersion = $version;
		$app->withTable(1, 'alice', 'Events')->withTable(2, 'bob', 'Other')
			->withColumn(['id' => 11, 'tableId' => 1, 'title' => 'Item', 'type' => 'text', 'subtype' => 'line'])
			->withColumn(['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => ''])
			->withColumn(['id' => 13, 'tableId' => 1, 'title' => 'Status', 'type' => 'selection', 'subtype' => '', 'selectionDefault' => '1',
				'selectionOptions' => [['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed'], ['id' => 10, 'label' => "A\u{0301}rvore"]]])
			->withColumn(['id' => 14, 'tableId' => 1, 'title' => 'Secret note', 'type' => 'text', 'subtype' => 'line'])
			->withColumn(['id' => 15, 'tableId' => 1, 'title' => 'Tags', 'type' => 'selection', 'subtype' => 'multi',
				'selectionOptions' => [['id' => 1, 'label' => 'red'], ['id' => 10, 'label' => 'Blue']]])
			->withColumn(['id' => 21, 'tableId' => 2, 'title' => 'Elsewhere', 'type' => 'text', 'subtype' => 'line'])
			->withView(31, 1, 'Open events', [11, 12, 13, 15])
			->withViewRules(31, self::VIEW_FILTER, self::VIEW_SORT)
			->sharingView(31, 'bob')
			->sharingTable(1, 'carol')
			->creatingOnly(1, 'gina');
		foreach ([1 => ['Alpha', 10, '1', 'ok one', '[1]'], 2 => ['Beta', 20, '10', 'ok two', '[10]'], 3 => ['Gamma', 10, '2', 'no', '[1,10]'],
			4 => ['Delta', 30, null, 'ok three', null], 5 => ['Secretive', 5, '2', 'needle ok', '[]']] as $id => [$item, $amount, $status, $secret, $tags]) {
			$app->withRow(1, $id, array_filter([11 => $item, 12 => $amount, 13 => $status, 14 => $secret, 15 => $tags], static fn (mixed $v): bool => $v !== null));
		}
		return $app;
	}
}
