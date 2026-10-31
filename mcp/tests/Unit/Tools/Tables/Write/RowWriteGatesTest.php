<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesWriteApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesWriteWiring;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\WriteScene;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tools\Tables\Write\RowWriteMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The two gates before any native parser or enhancer runs:
 * - a relation cell, null included, needs its target readable for the caller, the label column of the target (shown by
 *   a target view) and the target view's saved rules valid; otherwise the same fixed refusal, without RowService;
 * - a creation through a view needs the hidden values the native enhancer adds to be checkable: the whole saved filter
 *   valid, first match per column, each selected value in a raw form its column keeps (a canonical link included); multiple
 *   selections, users/groups and relations as hidden values are refused. Nothing hidden is shown.
 */
final class RowWriteGatesTest extends TestCase {
	/**
	 * A relation to a table the caller cannot read is refused for an existing id, an unknown id and null alike, before
	 * RowService; readable, the native call runs.
	 *
	 * @return void
	 */
	public function testARelationNeedsItsTargetReadable(): void {
		foreach ([7, 999, null] as $value) {
			$app = WriteScene::app();
			$this->assertSame(RowWriteMessages::relationRefused(), $this->refusal($app, 'alice', ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 21, 'value' => $value]]]));
			$this->assertSame([], $app->callsOf('RowService', 'create'));
		}
		$app = WriteScene::app()->grant('alice', 'table', 2, 'read');
		$this->write($app, 'alice', ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 21, 'value' => 7]]]);
		$this->assertCount(1, $app->callsOf('RowService', 'create'));
	}

	/**
	 * A relation to a view needs the view readable, its label column among the view's columns and of its table, and its saved
	 * rules valid; an incomplete setting is refused too.
	 *
	 * @return void
	 */
	public function testARelationToAViewNeedsWhatTheLookupReads(): void {
		$cases = [
			'label hidden by the view' => static fn (FakeTablesWriteApp $app) => $app->withView(51, 2, [32])->grant('alice', 'view', 51, 'read'),
			'label of another table' => static fn (FakeTablesWriteApp $app) => $app->withColumn(['id' => 24, 'tableId' => 1, 'type' => 'relation', 'subtype' => '',
				'customSettings' => ['relationType' => 'view', 'targetId' => 51, 'labelColumn' => 11]])->withView(51, 2, [11, 31])->grant('alice', 'view', 51, 'read'),
			'saved rule of another table' => static fn (FakeTablesWriteApp $app) => $app->withView(51, 2, [31], [], '[[{"columnId":11,"operator":"contains","value":"x"}]]')
				->grant('alice', 'view', 51, 'read'),
			'view not readable' => static fn (FakeTablesWriteApp $app) => $app->withView(51, 2, [31]),
			'incomplete setting' => static fn (FakeTablesWriteApp $app) => $app->withColumn(['id' => 24, 'tableId' => 1, 'type' => 'relation', 'subtype' => '',
				'customSettings' => ['relationType' => 'view', 'targetId' => 51]])->withView(51, 2, [31])->grant('alice', 'view', 51, 'read'),
		];
		foreach ($cases as $label => $setUp) {
			$app = self::withViewRelation();
			$setUp($app);
			$this->assertSame(RowWriteMessages::relationRefused(), $this->refusal($app, 'alice', ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 24, 'value' => 7]]]), $label);
		}
		$app = self::withViewRelation();
		$app->withView(51, 2, [31], [], '[[{"columnId":31,"operator":"contains","value":"H"}]]')->grant('alice', 'view', 51, 'read');
		$this->write($app, 'alice', ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 24, 'value' => 7]]]);
		$this->assertCount(1, $app->callsOf('RowService', 'create'));
	}

	/**
	 * First match per hidden column, in the native order: a first valid rule wins over a later invalid one; the other way
	 * round is refused, within a group and across groups; a rule on a column of another table refuses the whole filter.
	 *
	 * @return void
	 */
	public function testHiddenValuesFirstMatchWins(): void {
		$valid = '{"columnId":13,"operator":"is-equal","value":"@selection-id-1"}';
		$invalid = '{"columnId":13,"operator":"is-equal","value":"@selection-id-9"}';
		$app = self::hiddenStatus("[[$valid,$invalid]]");
		$id = $this->write($app, 'alice', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]);
		$this->assertSame('1', $app->row(1, $id)['data'][13]);
		$this->assertSame(RowWriteMessages::viewDefaultsRefused(), $this->refusal(self::hiddenStatus("[[$invalid,$valid]]"), 'alice', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]));
		$this->assertSame(RowWriteMessages::viewDefaultsRefused(), $this->refusal(self::hiddenStatus("[[$invalid],[$valid]]"), 'alice', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]),
			'the first group is walked first');
		$this->assertSame(RowWriteMessages::viewDefaultsRefused(), $this->refusal(self::hiddenStatus('[[{"columnId":31,"operator":"is-equal","value":"x"}]]'), 'alice',
			['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]), 'a column of another table');
	}

	/**
	 * The raw forms a hidden value may take: a canonical link is written as it is; a link with another key, provider or
	 * scheme, a multiple selection, a user/group and a relation are refused before RowService.
	 *
	 * @return void
	 */
	public function testHiddenValueForms(): void {
		$link = '{"title":"Docs","value":"https://example.org","providerId":"url"}';
		$app = self::hiddenRule(19, $link);
		$id = $this->write($app, 'alice', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]);
		$this->assertSame($link, $app->row(1, $id)['data'][19], 'the native enhancer wrote it as it is');
		foreach ([
			[19, '{"title":"Docs","value":"https://example.org","providerId":"url","x":1}'],
			[19, '{"title":"Docs","value":"https://example.org","providerId":"files"}'],
			[19, '{"title":"Docs","value":"javascript:alert(1)","providerId":"url"}'],
			[15, '@selection-id-1'],
			[20, '@me'],
			[21, '@relation-id-7'],
			[12, 'many'],
			[17, '7'],
			[26, '1'],
			[27, '90'],
		] as [$column, $value]) {
			$app = self::hiddenRule($column, $value);
			$this->assertSame(RowWriteMessages::viewDefaultsRefused(), $this->refusal($app, 'alice', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]), "$column $value");
			$this->assertSame([], $app->callsOf('RowService', 'create'));
		}
	}

	/**
	 * Hidden stars and progress values take the column's limits as well as their fixed range: the exact bounds are written,
	 * the values within the range but outside the limits are refused before RowService (above), with the same fixed
	 * message, naming nothing hidden.
	 *
	 * @return void
	 */
	public function testHiddenNumbersTakeTheColumnLimits(): void {
		foreach ([[26, '3'], [26, '4'], [27, '20'], [27, '80']] as [$column, $value]) {
			$app = self::hiddenRule($column, $value);
			$id = $this->write($app, 'alice', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a']]]);
			$this->assertSame($value, $app->row(1, $id)['data'][$column], "$column $value");
		}
	}

	/**
	 * The scene with relation column 24 to view 51 of table 2, label 31, and column 32 in table 2.
	 *
	 * @return FakeTablesWriteApp the app
	 */
	private static function withViewRelation(): FakeTablesWriteApp {
		return WriteScene::app()->withColumn(['id' => 24, 'tableId' => 1, 'type' => 'relation', 'subtype' => '',
			'customSettings' => ['relationType' => 'view', 'targetId' => 51, 'labelColumn' => 31]])->withColumn(['id' => 32, 'tableId' => 2]);
	}

	/**
	 * The scene with view 41 hiding Status (13) and filtering with the given rules.
	 *
	 * @param string $filter the saved filter
	 * @return FakeTablesWriteApp the app
	 */
	private static function hiddenStatus(string $filter): FakeTablesWriteApp {
		return WriteScene::app()->withView(41, 1, [11, 12, 15, 16, 21, 22], [22 => ['readonly' => true]], $filter);
	}

	/**
	 * The scene with view 41 adding one hidden value.
	 *
	 * @param int $column the hidden column
	 * @param string $value the value of its rule
	 * @return FakeTablesWriteApp the app
	 */
	private static function hiddenRule(int $column, string $value): FakeTablesWriteApp {
		$rule = json_encode([[['columnId' => $column, 'operator' => 'is-equal', 'value' => $value]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		return WriteScene::app()->withColumn(['id' => 26, 'tableId' => 1, 'title' => 'Level', 'type' => 'number', 'subtype' => 'stars', 'numberMin' => 3, 'numberMax' => 4])
			->withColumn(['id' => 27, 'tableId' => 1, 'title' => 'Share', 'type' => 'number', 'subtype' => 'progress', 'numberMin' => 20, 'numberMax' => 80])
			->withView(41, 1, [11], [], $rule);
	}

	/**
	 * Plans and confirms a creation as the caller.
	 *
	 * @param FakeTablesWriteApp $app the app
	 * @param string $uid the caller
	 * @param array<string, mixed> $arguments the arguments
	 * @return int the new row
	 */
	private function write(FakeTablesWriteApp $app, string $uid, array $arguments): int {
		$flow = TablesWriteWiring::flow($this, $app, $uid);
		$plan = $flow->preview('tables_create_row', $arguments, $uid, false);
		$result = $flow->handle('tables_create_row', $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT], 'confirm_shared' => true], $uid, false);
		$answer = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('requiresConfirmation', $result['content'][0]['text']);
		return (int)$answer['target']['rowId'];
	}

	/**
	 * The message of a refused plan.
	 *
	 * @param FakeTablesWriteApp $app the app
	 * @param string $uid the caller
	 * @param array<string, mixed> $arguments the arguments
	 * @return string the message
	 */
	private function refusal(FakeTablesWriteApp $app, string $uid, array $arguments): string {
		try {
			TablesWriteWiring::flow($this, $app, $uid)->preview('tables_create_row', $arguments, $uid, false);
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('expected a refusal');
	}
}
