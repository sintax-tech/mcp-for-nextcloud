<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

/**
 * The scene of the row write tests:
 * - alice owns table 1 "Events": 11 Title (text, mandatory, at most 50), 12 Amount (number 0..1000), 13 Status (selection
 *   1, 2), 14 Secret (text, hidden by view 41), 15 Tags (multiple selection 1, 10), 16 Done (yes/no), 17 Rating (stars),
 *   18 Due (date), 19 Site (link, provider url), 20 Owner (users), 21 Ref (relation to table 2, label 31), 22 Notes (text,
 *   read-only in view 41), 23 Code (unique text);
 * - bob owns table 2 "Places" with 31 Label;
 * - view 41 shows 11, 12, 13, 15, 16, 21, 22 of table 1, 22 read-only, filters on the hidden 14 (is-equal "ok"): creating
 *   through it adds Secret "ok";
 * - bob may create rows through view 41 and nothing else; carol may read, create and update table 1 (shared with her);
 *   erin may read and update through view 41; dave has nothing;
 * - row 5 (Alpha, 10, Secret "ok") is in the view, row 6 (Beta, Secret "no") is not.
 */
final class WriteScene {
	/** The table of the scene. */
	public const TABLE = 1;

	/** The view of the scene. */
	public const VIEW = 41;

	/** The saved filter of the view, on the hidden column. */
	public const VIEW_FILTER = '[[{"columnId":14,"operator":"is-equal","value":"ok"}]]';

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
	 * @return FakeTablesWriteApp the app
	 */
	public static function app(string $version = '2.3.2'): FakeTablesWriteApp {
		$app = new FakeTablesWriteApp();
		$app->tablesVersion = $version;
		$app->withTable(1, 'alice')->withTable(2, 'bob')
			->withColumn(['id' => 11, 'tableId' => 1, 'title' => 'Title', 'mandatory' => true, 'textMaxLength' => 50])
			->withColumn(['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => '', 'numberMin' => 0, 'numberMax' => 1000])
			->withColumn(['id' => 13, 'tableId' => 1, 'title' => 'Status', 'type' => 'selection', 'subtype' => '',
				'selectionOptions' => [['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed']]])
			->withColumn(['id' => 14, 'tableId' => 1, 'title' => 'Secret'])
			->withColumn(['id' => 15, 'tableId' => 1, 'title' => 'Tags', 'type' => 'selection', 'subtype' => 'multi',
				'selectionOptions' => [['id' => 1, 'label' => 'red'], ['id' => 10, 'label' => 'Blue']]])
			->withColumn(['id' => 16, 'tableId' => 1, 'title' => 'Done', 'type' => 'selection', 'subtype' => 'check'])
			->withColumn(['id' => 17, 'tableId' => 1, 'title' => 'Rating', 'type' => 'number', 'subtype' => 'stars'])
			->withColumn(['id' => 18, 'tableId' => 1, 'title' => 'Due', 'type' => 'datetime', 'subtype' => 'date'])
			->withColumn(['id' => 19, 'tableId' => 1, 'title' => 'Site', 'type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url'])
			->withColumn(['id' => 20, 'tableId' => 1, 'title' => 'Owner', 'type' => 'usergroup', 'subtype' => '', 'usergroupSelectUsers' => true])
			->withColumn(['id' => 21, 'tableId' => 1, 'title' => 'Ref', 'type' => 'relation', 'subtype' => '',
				'customSettings' => ['relationType' => 'table', 'targetId' => 2, 'labelColumn' => 31]])
			->withColumn(['id' => 22, 'tableId' => 1, 'title' => 'Notes'])
			->withColumn(['id' => 23, 'tableId' => 1, 'title' => 'Code', 'textUnique' => true])
			->withColumn(['id' => 31, 'tableId' => 2, 'title' => 'Label'])
			->withView(41, 1, [11, 12, 13, 15, 16, 21, 22], [22 => ['readonly' => true]], self::VIEW_FILTER, '[{"columnId":12,"mode":"ASC"}]')
			->withRow(1, 5, [11 => 'Alpha', 12 => 10.0, 14 => 'ok'])
			->withRow(1, 6, [11 => 'Beta', 14 => 'no'])
			->withRow(2, 7, [31 => 'Hall'])
			->grant('bob', 'view', 41, 'create')
			->grant('carol', 'table', 1, 'read', 'create', 'update')
			->grant('erin', 'view', 41, 'read', 'update');
		return $app;
	}
}
