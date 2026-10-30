<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesRowsApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\RowsScene;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesRowsWiring;
use OCA\Mcp\Tools\Tables\Rows\RowCellText;
use OCA\Mcp\Tools\Tables\Rows\RowsMessages;
use OCA\Mcp\Tools\Tables\Rows\RowsRequest;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The reader asks the native row permission first, checks the saved rules and the user's query against the columns the
 * user can see, composes V ∧ U ∧ S, checks the session and only then calls the engine, with the visible columns,
 * limit + 1, the offset of a checked cursor, the composed filter, a total sort and the caller's id.
 */
final class RowReaderTest extends TestCase {
	/**
	 * Bob through the view: the engine gets the view's columns, the hidden saved filter in every group, the user's filter
	 * and the search composed, the saved sort with the id appended, limit + 1 and bob's id; the cells are the visible ones.
	 *
	 * @return void
	 */
	public function testTheEngineGetsTheComposedQuery(): void {
		$app = RowsScene::app();
		$page = $this->page($app, 'bob', ['view_id' => 31, 'filter' => [[['column_id' => 12, 'operator' => 'is-greater-than', 'value' => 5]], [['column_id' => 13, 'operator' => 'is-equal', 'value' => 10]]], 'search' => 'blue', 'limit' => 2]);
		[[$columns, $tableId, $limit, $offset, $filter, $sort, $uid]] = $app->callsOf('Row2Mapper', 'findAll');
		$this->assertSame([[11, 12, 13, 15], 1, 3, 0, 'bob'], [$columns, $tableId, $limit, $offset, $uid]);
		$this->assertCount(2 * 2, $filter, 'V1 × U2 × S2');
		foreach ($filter as $group) {
			$this->assertSame(['columnId' => 14, 'operator' => 'contains', 'value' => 'ok'], $group[0], 'the hidden saved filter opens every group');
			$this->assertCount(3, $group);
		}
		$this->assertSame([['columnId' => 12, 'mode' => 'ASC'], ['columnId' => -1, 'mode' => 'ASC']], $sort);
		$this->assertTrue($page['hasMore']);
		$this->assertCount(2, $page['rows']);
		$this->assertSame([11, 12, 13, 15], array_values(array_unique(array_merge(...array_map(static fn (array $r): array => array_column($r['cells'], 'columnId'), $page['rows'])))));
		$this->assertStringNotContainsString('dataByAlias', json_encode($page, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('ok one', json_encode($page, JSON_THROW_ON_ERROR), 'the hidden column never comes back');
	}

	/**
	 * The session is checked right before the engine: another user or no user is refused without any engine call, after
	 * the permission was asked for the caller.
	 *
	 * @return void
	 */
	public function testTheSessionIsCheckedBeforeTheEngine(): void {
		foreach (['alice', null] as $session) {
			$app = RowsScene::app();
			$this->assertSame(RowsMessages::sessionMismatch(), $this->failure($app, 'bob', ['view_id' => 31], $session));
			$this->assertSame([], $app->callsOf('Row2Mapper', 'findAll'));
			$this->assertSame([[31, 'view', 'bob']], $app->callsOf('PermissionsService', 'canReadRowsByElementId'));
		}
		$app = RowsScene::app();
		$this->assertSame(RowsMessages::sessionMismatch(), $this->failure($app, '', ['view_id' => 31], ''));
		$this->assertSame([], $app->journal->getArrayCopy(), 'an empty caller reaches nothing of Tables');
	}

	/**
	 * A create-only share, a stranger and a missing table read like not found, with no engine call; carol reads the table
	 * with all its columns; bob cannot read the table behind his view.
	 *
	 * @return void
	 */
	public function testThePermissionIsTheNativeOne(): void {
		foreach ([['gina', ['table_id' => 1]], ['dave', ['table_id' => 1]], ['bob', ['table_id' => 1]], ['alice', ['table_id' => 404]], ['dave', ['view_id' => 31]]] as [$uid, $args]) {
			$app = RowsScene::app();
			$this->assertSame(TablesMessages::notFound(), $this->failure($app, $uid, $args), $uid);
			$this->assertSame([], $app->callsOf('Row2Mapper', 'findAll'));
		}
		$app = RowsScene::app();
		$this->page($app, 'carol', ['table_id' => 1]);
		$this->assertSame([11, 12, 13, 14, 15], $app->callsOf('Row2Mapper', 'findAll')[0][0]);
		$this->assertNull($app->callsOf('Row2Mapper', 'findAll')[0][4], 'a table has no saved filter');
	}

	/**
	 * A user filter, search or sort on the hidden column is refused before the engine, as an option of another column,
	 * and a search no column can match returns nothing without the engine.
	 *
	 * @return void
	 */
	public function testTheUserStaysOnVisibleColumns(): void {
		foreach ([
			['view_id' => 31, 'filter' => [[['column_id' => 14, 'operator' => 'contains', 'value' => 'ok']]]],
			['view_id' => 31, 'filter' => [[['column_id' => 21, 'operator' => 'contains', 'value' => 'x']]]],
			['view_id' => 31, 'sort' => [['column_id' => 14, 'mode' => 'ASC']]],
			['view_id' => 31, 'filter' => [[['column_id' => 13, 'operator' => 'is-equal', 'value' => 3]]]],
		] as $args) {
			$app = RowsScene::app();
			$this->assertSame(RowsMessages::invalidQuery(), $this->failure($app, 'bob', $args), json_encode($args));
			$this->assertSame([], $app->callsOf('Row2Mapper', 'findAll'));
		}
		$app = RowsScene::app();
		$app->withViewRules(31, RowsScene::VIEW_FILTER, RowsScene::VIEW_SORT);
		$page = $this->page($app, 'bob', ['view_id' => 31, 'search' => 'zzz-no-such-label', 'filter' => [[['column_id' => 12, 'operator' => 'is-empty']]]]);
		$this->assertCount(1, $app->callsOf('Row2Mapper', 'findAll'), 'the text column is still a candidate');
		$noText = RowsScene::app();
		$noText->withView(32, 1, 'Numbers', [12, 13])->withViewRules(32, null, null)->sharingView(32, 'bob');
		$this->assertSame(['rows' => [], 'hasMore' => false, 'nextCursor' => null, 'offsetLimitReached' => false, 'truncatedCells' => 0], $this->page($noText, 'bob', ['view_id' => 32, 'search' => 'zzz']));
		$this->assertSame([], $noText->callsOf('Row2Mapper', 'findAll'), 'FALSE never reaches the engine');
		unset($page);
	}

	/**
	 * Saved rules on another table's column, or malformed, refuse the whole query before the engine.
	 *
	 * @return void
	 */
	public function testUnsafeSavedRulesRefuse(): void {
		foreach ([['[[{"columnId":21,"operator":"contains","value":"x"}]]', null], [RowsScene::VIEW_FILTER, '[{"columnId":21,"mode":"ASC"}]'], ['[[]]', null]] as [$filter, $sort]) {
			$app = RowsScene::app();
			$app->withViewRules(31, $filter, $sort);
			$this->assertSame(RowsMessages::savedRulesUnusable(), $this->failure($app, 'bob', ['view_id' => 31]));
			$this->assertSame([], $app->callsOf('Row2Mapper', 'findAll'));
		}
	}

	/**
	 * The next page takes the offset of the cursor; a change of the view's filter between the pages refuses the cursor.
	 *
	 * @return void
	 */
	public function testTheCursorFollowsTheState(): void {
		$app = RowsScene::app();
		$first = $this->page($app, 'bob', ['view_id' => 31, 'limit' => 2]);
		$second = $this->page($app, 'bob', ['view_id' => 31, 'limit' => 2, 'cursor' => $first['nextCursor']]);
		$this->assertSame(2, $app->callsOf('Row2Mapper', 'findAll')[1][3]);
		$this->assertNotSame(array_column($first['rows'], 'id'), array_column($second['rows'], 'id'));
		$app->withViewRules(31, '[[{"columnId":14,"operator":"contains","value":"two"}]]', RowsScene::VIEW_SORT);
		$this->assertSame(RowsMessages::staleCursor(), $this->failure($app, 'bob', ['view_id' => 31, 'limit' => 2, 'cursor' => $first['nextCursor']]));
		$this->assertSame(RowsMessages::staleCursor(), $this->failure(RowsScene::app(), 'bob', ['view_id' => 31, 'limit' => 3, 'cursor' => $first['nextCursor']]), 'another page size');
	}

	/**
	 * The state digest holds the columns the saved rules name, the hidden one included: a change of the hidden column's
	 * default alone refuses the cursor, while an unchanged state, or a change of a column no rule or projection names,
	 * keeps it valid. The hidden column never comes back.
	 *
	 * @return void
	 */
	public function testTheCursorFollowsTheHiddenRuleColumn(): void {
		$app = RowsScene::app();
		$first = $this->page($app, 'bob', ['view_id' => 31, 'limit' => 2]);
		$this->assertStringNotContainsString('Secret note', json_encode($first, JSON_THROW_ON_ERROR));
		$app->withColumn(['id' => 21, 'tableId' => 2, 'title' => 'Elsewhere', 'type' => 'text', 'subtype' => 'line', 'textDefault' => 'other']);
		$this->page($app, 'bob', ['view_id' => 31, 'limit' => 2, 'cursor' => $first['nextCursor']]);
		$this->assertSame(2, $app->callsOf('Row2Mapper', 'findAll')[1][3], 'the stable control stays valid');
		$app->withColumn(['id' => 14, 'tableId' => 1, 'title' => 'Secret note', 'type' => 'text', 'subtype' => 'line', 'textDefault' => 'ok by default']);
		$this->assertSame(RowsMessages::staleCursor(), $this->failure($app, 'bob', ['view_id' => 31, 'limit' => 2, 'cursor' => $first['nextCursor']]));
		$this->assertCount(2, $app->callsOf('Row2Mapper', 'findAll'), 'the stale cursor never reaches the engine');
	}

	/**
	 * The cursors made stop at the same boundary the cursors read: the page whose next offset is the boundary gets a
	 * cursor that reads back; past it the page keeps hasMore, says offsetLimitReached and gets no cursor; the last page
	 * of the data says neither.
	 *
	 * @return void
	 */
	public function testTheCursorStopsAtTheBoundary(): void {
		$app = RowsScene::app();
		$arguments = ['table_id' => 1, 'limit' => 2];
		$first = TablesRowsWiring::reader($app, 'alice', 2)->page(RowsRequest::of($arguments), 'alice');
		$this->assertSame([true, false], [$first['hasMore'], $first['offsetLimitReached']]);
		$second = TablesRowsWiring::reader($app, 'alice', 2)->page(RowsRequest::of($arguments + ['cursor' => $first['nextCursor']]), 'alice');
		$this->assertSame([2, 3], [$app->callsOf('Row2Mapper', 'findAll')[1][3], $app->callsOf('Row2Mapper', 'findAll')[1][2]], 'the boundary offset reads back, with limit + 1');
		$this->assertSame([true, null, true], [$second['hasMore'], $second['nextCursor'], $second['offsetLimitReached']]);
		$whole = TablesRowsWiring::reader($app, 'alice', 4)->page(RowsRequest::of(['table_id' => 1, 'limit' => 5]), 'alice');
		$this->assertSame([false, null, false], [$whole['hasMore'], $whole['nextCursor'], $whole['offsetLimitReached']]);
	}

	/**
	 * Long text comes back whole up to the cell budget, with line breaks and tabs, cleaned of controls; past the budget
	 * the cell is cut, says so with its length, and the page counts it; the page budget cuts the later cells the same way.
	 *
	 * @return void
	 */
	public function testLongTextKeepsItsLengthOrSaysItWasCut(): void {
		$long = str_repeat("Linha longa\tcom tab\n", 200);
		$app = RowsScene::app();
		$app->withRow(1, 1, [11 => $long . "\u{202E}\x07", 12 => 10])->withRow(1, 2, [11 => str_repeat('é', RowCellText::MAX_CELL_CHARS + 5)]);
		$page = $this->page($app, 'carol', ['table_id' => 1, 'limit' => 2]);
		$this->assertGreaterThan(2000, mb_strlen($long));
		$this->assertSame(['columnId' => 11, 'value' => $long], $page['rows'][0]['cells'][0], 'kept whole, only the controls removed');
		$this->assertSame(['columnId' => 11, 'value' => str_repeat('é', RowCellText::MAX_CELL_CHARS), 'truncated' => true, 'totalChars' => RowCellText::MAX_CELL_CHARS + 5], $page['rows'][1]['cells'][0]);
		$this->assertSame(1, $page['truncatedCells']);
		$big = RowsScene::app();
		foreach (range(1, 6) as $id) {
			$big->withRow(1, $id, [11 => str_repeat('x', RowCellText::MAX_CELL_CHARS)]);
		}
		$page = $this->page($big, 'carol', ['table_id' => 1, 'limit' => 6]);
		$lengths = array_map(static fn (array $row): int => mb_strlen($row['cells'][0]['value']), $page['rows']);
		$this->assertSame([20000, 20000, 20000, 20000, 20000, 0], $lengths);
		$this->assertSame(RowCellText::MAX_PAGE_CHARS, array_sum($lengths));
		$this->assertSame([true, RowCellText::MAX_CELL_CHARS], [$page['rows'][5]['cells'][0]['truncated'], $page['rows'][5]['cells'][0]['totalChars']]);
		$this->assertSame(1, $page['truncatedCells']);
	}

	/**
	 * A table's saved sort applies from 2.2.2 on, and is never asked before.
	 *
	 * @return void
	 */
	public function testTheTableSortByRelease(): void {
		$app = RowsScene::app('2.2.2');
		$app->withTableSort(1, '[{"columnId":12,"mode":"DESC"}]');
		$this->page($app, 'alice', ['table_id' => 1]);
		$this->assertSame([['columnId' => 12, 'mode' => 'DESC'], ['columnId' => -1, 'mode' => 'ASC']], $app->callsOf('Row2Mapper', 'findAll')[0][5]);
		$old = RowsScene::app('1.0.10');
		$old->withTableSort(1, '[{"columnId":12,"mode":"DESC"}]');
		$this->page($old, 'alice', ['table_id' => 1]);
		$this->assertSame([['columnId' => -1, 'mode' => 'ASC']], $old->callsOf('Row2Mapper', 'findAll')[0][5]);
		$this->assertSame([], $old->callsOf('TableService', 'find'));
	}

	/**
	 * One page read for a user, with the caller as session user unless given.
	 *
	 * @param FakeTablesRowsApp $app the app
	 * @param string $uid the caller
	 * @param array<string, mixed> $arguments the arguments
	 * @param string|null|false $session the session user, false for the caller
	 * @return array<string, mixed> the page
	 */
	private function page(FakeTablesRowsApp $app, string $uid, array $arguments, string|null|false $session = false): array {
		return TablesRowsWiring::reader($app, $session === false ? $uid : $session)->page(RowsRequest::of($arguments), $uid);
	}

	/**
	 * The message of a refused read.
	 *
	 * @param FakeTablesRowsApp $app the app
	 * @param string $uid the caller
	 * @param array<string, mixed> $arguments the arguments
	 * @param string|null|false $session the session user, false for the caller
	 * @return string the message
	 */
	private function failure(FakeTablesRowsApp $app, string $uid, array $arguments, string|null|false $session = false): string {
		try {
			$this->page($app, $uid, $arguments, $session);
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('expected a refusal');
	}
}
