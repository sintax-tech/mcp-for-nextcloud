<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Handler;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesHandlers;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * tables_list_columns: the columns of exactly one table or view, with a link; out of reach reads like missing.
 */
final class ListColumnsHandlerTest extends TestCase {
	/**
	 * By table, every column; by view, only the visible ones with the view's settings.
	 *
	 * @return void
	 */
	public function testByTableAndByView(): void {
		$app = TablesScene::app();
		$table = TablesHandlers::answer($this, $app, 'tables_list_columns', ['table_id' => 1], 'carol');
		$this->assertSame([1, TablesScene::BASE . '#/table/1', [11, 12, 13]], [$table['tableId'], $table['url'], array_column($table['columns'], 'id')]);
		$this->assertArrayNotHasKey('view', $table['columns'][0]);
		$view = TablesHandlers::answer($this, $app, 'tables_list_columns', ['view_id' => 21], 'bob');
		$this->assertSame([21, TablesScene::BASE . '#/view/21', [11, 12]], [$view['viewId'], $view['url'], array_column($view['columns'], 'id')]);
		$this->assertSame([['readonly' => false, 'mandatory' => false], ['readonly' => true, 'mandatory' => false]], array_column($view['columns'], 'view'));
		$this->assertSame(TablesMessages::UNTRUSTED_NOTICE, $view['notice']);
		$this->assertStringNotContainsString('Secret status', json_encode($view, JSON_THROW_ON_ERROR));
	}

	/**
	 * Through a view, a visible relation column comes with its relation keys and a hidden one does not come at all.
	 *
	 * @return void
	 */
	public function testAViewShowsItsRelationColumnAndHidesTheOther(): void {
		$app = (new FakeTablesApp())->withTable(1, 'alice', 'Budget')
			->withColumn(['id' => 31, 'tableId' => 1, 'title' => 'Project', 'type' => 'relation', 'subtype' => '', 'description' => '', 'mandatory' => false,
				'customSettings' => ['relationType' => 'view', 'targetId' => 7, 'labelColumn' => 3, 'width' => 200]])
			->withColumn(['id' => 32, 'tableId' => 1, 'title' => 'Hidden link', 'type' => 'relation', 'subtype' => '', 'description' => '', 'mandatory' => false,
				'customSettings' => ['relationType' => 'table', 'targetId' => 99, 'labelColumn' => 4]])
			->withView(21, 1, 'Approved', [31])
			->sharingView(21, 'bob');
		$view = TablesHandlers::answer($this, $app, 'tables_list_columns', ['view_id' => 21], 'bob');
		$this->assertSame([31], array_column($view['columns'], 'id'));
		$this->assertSame(['relationType' => 'view', 'targetId' => 7, 'labelColumn' => 3], $view['columns'][0]['options']);
		$this->assertStringNotContainsString('Hidden link', json_encode($view, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('99', json_encode($view, JSON_THROW_ON_ERROR));
		$table = TablesHandlers::answer($this, $app, 'tables_list_columns', ['table_id' => 1], 'alice');
		$this->assertSame([31, 32], array_column($table['columns'], 'id'));
	}

	/**
	 * Both or neither of table_id and view_id is an invalid call, before the app is asked.
	 *
	 * @return void
	 */
	public function testExactlyOneOfTableAndView(): void {
		$app = TablesScene::app();
		foreach ([[], ['table_id' => 1, 'view_id' => 21]] as $arguments) {
			try {
				TablesHandlers::answer($this, $app, 'tables_list_columns', $arguments, 'alice');
				$this->fail('expected an invalid call');
			} catch (ArgumentValidationException $e) {
				$this->assertSame(['field' => 'arguments', 'rule' => TablesMessages::oneOfTableOrView()], $e->details());
			}
		}
		$this->assertSame([], $app->journal->getArrayCopy());
	}

	/**
	 * Another user's table, a view of a table the user only reads and a missing id fail the same way, with nothing of
	 * the Tables exception in the message.
	 *
	 * @return void
	 */
	public function testOutOfReachReadsLikeMissing(): void {
		$messages = [];
		foreach ([[['table_id' => 1], 'dave'], [['view_id' => 21], 'carol'], [['table_id' => 404], 'alice'], [['view_id' => 404], 'alice']] as [$arguments, $uid]) {
			try {
				TablesHandlers::answer($this, TablesScene::app(), 'tables_list_columns', $arguments, $uid);
				$this->fail('expected a failure');
			} catch (ToolFailure $e) {
				$messages[] = $e->getMessage();
			}
		}
		$this->assertSame(array_fill(0, 4, TablesMessages::notFound()), $messages);
	}
}
