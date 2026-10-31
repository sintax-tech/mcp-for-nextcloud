<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesWriteApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesWriteWiring;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\WriteScene;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\Write\RowWriteMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The plan and the confirmed call of tables_create_row and tables_update_row over a strict fake of the Tables app: a plan
 * never reaches RowService; a confirmed call checks everything again, compares the plan state, asks for the shared
 * confirmation, and makes the one native call in the controllers' form; only the preflight says "nothing written", any
 * exception out of RowService is uncertain; nothing hidden reaches a plan, an error or a receipt.
 */
final class RowWriteFlowTest extends TestCase {
	/** Create tool. */
	private const CREATE = 'tables_create_row';

	/** Update tool. */
	private const UPDATE = 'tables_update_row';

	/**
	 * A plan reads metadata only and writes nothing; its confirmation makes RowService::create($table, null, $data) once,
	 * and the receipt holds the sent cells only, with what the service returned.
	 *
	 * @return void
	 */
	public function testThePlanWritesNothingAndTheConfirmationWritesOnce(): void {
		$app = WriteScene::app();
		$arguments = ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'Gamma'], ['column_id' => 15, 'value' => [10, 1]], ['column_id' => 16, 'value' => false]]];
		$plan = $this->preview($app, 'alice', self::CREATE, $arguments);
		$this->assertSame([], $app->callsOf('RowService', 'create'));
		$this->assertSame([['columnId' => 11, 'column' => 'Title', 'after' => 'Gamma'], ['columnId' => 15, 'column' => 'Tags', 'after' => [1, 10]],
			['columnId' => 16, 'column' => 'Done', 'after' => 'false']], $plan['cells']);
		$this->assertSame(['tableId' => 1, 'url' => TablesScene::BASE . '#/table/1'], $plan['target']);
		$answer = $this->confirm($app, 'alice', self::CREATE, $arguments, $plan);
		$this->assertSame([[1, null, [['columnId' => 11, 'value' => 'Gamma'], ['columnId' => 15, 'value' => [10, 1]], ['columnId' => 16, 'value' => false]]]],
			$app->callsOf('RowService', 'create'));
		$this->assertSame([['columnId' => 11, 'requested' => 'Gamma', 'returned' => 'Gamma'], ['columnId' => 15, 'requested' => [1, 10], 'returned' => [1, 10]],
			['columnId' => 16, 'requested' => 'false', 'returned' => 'false']], $answer['cells']);
		$this->assertArrayNotHasKey('notes', $answer, 'the owner, no view, no unique column');
		$this->assertSame(1000 + 2, $answer['target']['rowId']);
	}

	/**
	 * A share of the view that only allows creating: the plan and the creation read no row, use no read lookup and no table,
	 * and the hidden value the view adds is written by RowService but never shown.
	 *
	 * @return void
	 */
	public function testCreateOnlyThroughTheViewReadsNoRow(): void {
		$app = WriteScene::app();
		$arguments = ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'From bob']]];
		$plan = $this->preview($app, 'bob', self::CREATE, $arguments);
		$answer = $this->confirm($app, 'bob', self::CREATE, $arguments + ['confirm_shared' => true], $plan);
		$this->assertSame([], $app->callsOf('Row2Mapper', 'findAll'), 'no row read');
		$this->assertSame([], $app->callsOf('PermissionsService', 'canReadRowsByElementId'), 'no read permission asked');
		$this->assertSame([[null, 41, [['columnId' => 11, 'value' => 'From bob']]]], $app->callsOf('RowService', 'create'));
		$this->assertSame('ok', $app->row(1, $answer['target']['rowId'])['data'][14], 'the native enhancer wrote the hidden value');
		foreach ([$plan, $answer] as $shown) {
			$json = json_encode($shown, JSON_THROW_ON_ERROR);
			$this->assertStringNotContainsString('Secret', $json);
			$this->assertStringNotContainsString('"columnId":14', $json);
		}
		$this->assertSame([RowWriteMessages::viewCreateNote(), RowWriteMessages::shared()], $plan['notes']);
		$this->assertSame(TablesMessages::notFound(), $this->refusal($app, 'bob', self::UPDATE, ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 11, 'value' => 'x']]]));
		$this->assertSame(TablesMessages::notFound(), $this->refusal($app, 'bob', self::CREATE, ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'x']]]), 'no table behind the view');
	}

	/**
	 * Updating through the view: the row must be in it (row 6 is not), the plan shows the visible value before and after,
	 * the user of the view never reaches the table, and reading without updating is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateGoesThroughTheTargetOnly(): void {
		$app = WriteScene::app();
		$arguments = ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 25]]];
		$plan = $this->preview($app, 'erin', self::UPDATE, $arguments);
		$this->assertSame([['columnId' => 12, 'column' => 'Amount', 'before' => 10.0, 'after' => 25.0]], $plan['cells']);
		$this->assertSame([RowWriteMessages::shared()], $plan['notes'], 'Amount is not in the filter; the table is alice\'s');
		$answer = $this->confirm($app, 'erin', self::UPDATE, $arguments + ['confirm_shared' => true], $plan);
		$this->assertSame([[5, 41, [['columnId' => 12, 'value' => 25]], 'erin', null]], $app->callsOf('RowService', 'updateSet'));
		$this->assertSame(25.0, $app->row(1, 5)['data'][12]);
		$this->assertSame(5, $answer['target']['rowId']);
		$this->assertSame(TablesMessages::notFound(), $this->refusal($app, 'erin', self::UPDATE, ['view_id' => 41, 'row_id' => 6, 'cells' => [['column_id' => 12, 'value' => 1]]]));
		$this->assertSame(TablesMessages::notFound(), $this->refusal($app, 'erin', self::UPDATE, ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 1]]]));
		$app->grant('frank', 'table', 1, 'read');
		$this->assertSame(TablesMessages::notFound(), $this->refusal($app, 'frank', self::UPDATE, ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 1]]]));
	}

	/**
	 * Cells on a hidden, read-only, foreign or unknown column, a mandatory column left out or emptied, and a value outside
	 * the domain are refused before RowService, with fixed messages.
	 *
	 * @return void
	 */
	public function testRefusedCellsWriteNothing(): void {
		$app = WriteScene::app();
		foreach ([
			[['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 14, 'value' => 'x']]], RowWriteMessages::columnRefused()],
			[['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 22, 'value' => 'x']]], RowWriteMessages::columnRefused()],
			[['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 31, 'value' => 'x']]], RowWriteMessages::columnRefused()],
			[['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 99, 'value' => 'x']]], RowWriteMessages::columnRefused()],
			[['table_id' => 1, 'cells' => [['column_id' => 12, 'value' => 1]]], RowWriteMessages::mandatoryMissing()],
			[['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => null]]], RowWriteMessages::mandatoryMissing()],
			[['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => str_repeat('x', 51)]]], RowWriteMessages::valueRefused()],
			[['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 13, 'value' => 3]]], RowWriteMessages::valueRefused()],
		] as $i => [$arguments, $message]) {
			$this->assertSame($message, $this->refusal($app, 'alice', self::CREATE, $arguments), "case $i");
		}
		$this->assertSame([], $app->callsOf('RowService', 'create'));
		$this->assertSame([5, 6], $app->rowIds(1));
	}

	/**
	 * Every change between the plan and the confirmation gives the new plan and writes nothing: a limit raised with the
	 * value still valid, the view's filter changed with the row still in it, only the end of a long text changed with the
	 * same last edit time, the owner changed, another argument; the same state writes.
	 *
	 * @return void
	 */
	public function testAChangedStateGivesANewPlan(): void {
		$long = str_repeat('x', 30000);
		$cases = [
			'limit' => fn (FakeTablesWriteApp $app) => $app->withColumn(['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => '', 'numberMin' => 0, 'numberMax' => 2000]),
			'filter' => fn (FakeTablesWriteApp $app) => $app->withView(41, 1, [11, 12, 13, 15, 16, 21, 22], [22 => ['readonly' => true]],
				'[[{"columnId":14,"operator":"contains","value":"o"}]]', '[{"columnId":12,"mode":"ASC"}]'),
			'suffix' => fn (FakeTablesWriteApp $app) => $app->withRow(1, 5, [11 => 'Alpha', 12 => 10.0, 14 => 'ok', 22 => $long . 'y']),
			'owner' => fn (FakeTablesWriteApp $app) => $app->withTable(1, 'carol')->grant('alice', 'view', 41, 'read', 'update'),
		];
		foreach ($cases as $label => $change) {
			$app = WriteScene::app();
			$app->withRow(1, 5, [11 => 'Alpha', 12 => 10.0, 14 => 'ok', 22 => $long]);
			$arguments = ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 25]]];
			$plan = $this->preview($app, 'alice', self::UPDATE, $arguments);
			$change($app);
			try {
				$this->confirm($app, 'alice', self::UPDATE, $arguments + ['confirm_shared' => true], $plan);
				$this->fail("$label: written");
			} catch (PlanChanged $e) {
				$message = $e->plan['warnings'][0]['message'];
				$this->assertNotSame('', $message, $label);
				$this->assertStringContainsString('numberMax', $message, $label);
				if ($label === 'limit') {
					$this->assertStringContainsString('2000', $message, $label);
				}
				$this->assertNotSame($plan[PlanState::ARGUMENT], $e->plan[PlanState::ARGUMENT], $label);
			}
			$this->assertSame([], $app->callsOf('RowService', 'updateSet'), $label);
		}
		$app = WriteScene::app();
		$plan = $this->preview($app, 'alice', self::UPDATE, ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 25]]]);
		$this->expectException(PlanChanged::class);
		$this->confirm($app, 'alice', self::UPDATE, ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 26]]], $plan);
	}

	/**
	 * A hidden value that resolves to something else at the confirmation (the clock, the user's name) gives a new plan
	 * without RowService; the same value keeps the plan.
	 *
	 * @return void
	 */
	public function testADynamicHiddenValueIsBound(): void {
		$app = WriteScene::app();
		$app->withColumn(['id' => 14, 'tableId' => 1, 'title' => 'Secret', 'type' => 'datetime', 'subtype' => 'date'])
			->withView(41, 1, [11, 12, 13, 15, 16, 21, 22], [22 => ['readonly' => true]], '[[{"columnId":14,"operator":"is-equal","value":"@datetime-date-today"}]]');
		$app->resolveSearch = static fn (string $placeholder, string $userId): string => '2026-10-26';
		$arguments = ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'Today']]];
		$plan = $this->preview($app, 'alice', self::CREATE, $arguments);
		$app->resolveSearch = static fn (string $placeholder, string $userId): string => '2026-10-27';
		try {
			$this->confirm($app, 'alice', self::CREATE, $arguments, $plan);
			$this->fail('written with another hidden value');
		} catch (PlanChanged) {
			$this->assertSame([], $app->callsOf('RowService', 'create'));
		}
		$plan = $this->preview($app, 'alice', self::CREATE, $arguments);
		$answer = $this->confirm($app, 'alice', self::CREATE, $arguments, $plan);
		$this->assertSame('2026-10-27', $app->row(1, $answer['target']['rowId'])['data'][14]);
	}

	/**
	 * Only the preflight says nothing was written. An exception out of RowService, whatever its class and whether it came
	 * before or after the write, is uncertain, with a recovery that needs no row id for a creation; nothing is retried.
	 *
	 * @return void
	 */
	public function testOnlyThePreflightIsCertain(): void {
		foreach (['refuse', 'failAfter'] as $sink) {
			$app = WriteScene::app();
			$arguments = ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'Gamma']]];
			$plan = $this->preview($app, 'alice', self::CREATE, $arguments);
			$app->sink = $sink;
			$this->assertSame(RowWriteMessages::createUncertain(), $this->refusal($app, 'alice', self::CREATE, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], true), $sink);
			$this->assertCount(1, $app->callsOf('RowService', 'create'), "$sink: called once, never retried");
			$this->assertSame($sink === 'failAfter' ? [5, 6, 1002] : [5, 6], $app->rowIds(1), $sink);
			$update = ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 30]]];
			$plan = $this->preview($app->withTable(1, 'alice'), 'alice', self::UPDATE, $update);
			$this->assertSame(RowWriteMessages::updateUncertain(5), $this->refusal($app, 'alice', self::UPDATE, $update + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], true), $sink);
		}
	}

	/**
	 * A table of somebody else asks for confirm_shared before writing, without naming the owner; the session must be the
	 * caller's before anything of Tables is touched.
	 *
	 * @return void
	 */
	public function testSharedTablesAndTheSession(): void {
		$app = WriteScene::app();
		$arguments = ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'Carol']]];
		$plan = $this->preview($app, 'carol', self::CREATE, $arguments);
		$this->assertTrue($plan['shared']);
		$this->assertStringNotContainsString('alice', json_encode($plan, JSON_THROW_ON_ERROR));
		$asked = $this->answer(TablesWriteWiring::flow($this, $app, 'carol')->handle(self::CREATE, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'carol', false));
		$this->assertSame(['requiresConfirmation' => true, 'scope' => 'shared'], array_intersect_key($asked, ['requiresConfirmation' => 1, 'scope' => 1]));
		$this->assertStringNotContainsString('alice', json_encode($asked, JSON_THROW_ON_ERROR));
		$this->assertSame([], $app->callsOf('RowService', 'create'));
		$this->confirm($app, 'carol', self::CREATE, $arguments + ['confirm_shared' => true], $plan);
		$this->assertCount(1, $app->callsOf('RowService', 'create'));
		foreach (['alice', null] as $session) {
			$app = WriteScene::app();
			$this->assertSame(RowWriteMessages::sessionMismatch(), $this->refusal($app, 'carol', self::CREATE, $arguments, false, $session));
			$this->assertSame([], $app->journal->getArrayCopy(), 'nothing of Tables before the session check');
		}
	}

	/**
	 * A federated target is refused from Tables 2.3 on; before, isFederated() is never asked.
	 *
	 * @return void
	 */
	public function testFederatedTargetsAreRefused(): void {
		$app = WriteScene::app();
		$app->withTable(1, 'alice', true);
		$this->assertSame(RowWriteMessages::federated(), $this->refusal($app, 'alice', self::CREATE, ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a']]]));
		$old = WriteScene::app('2.2.2');
		$old->withTable(1, 'alice', true);
		$this->preview($old, 'alice', self::CREATE, ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a']]]);
		$this->assertSame([], $old->callsOf('Table#1', 'isFederated'));
	}

	/**
	 * A missing number: before Tables 2.3.2 the number mapper stores 0 for it, so null is refused before the plan, on a
	 * creation and on an update, and nothing is written; numbers are written as usual. From 2.3.2 the mapper keeps it
	 * empty, and null empties the cell.
	 *
	 * @return void
	 */
	public function testANullNumberFollowsTheMapper(): void {
		foreach (['1.0.0', '1.0.10', '2.0.0', '2.2.2'] as $version) {
			$app = WriteScene::app($version);
			$this->assertSame(RowWriteMessages::valueRefused(), $this->refusal($app, 'alice', self::CREATE, ['table_id' => 1, 'cells' => [['column_id' => 11, 'value' => 'a'], ['column_id' => 12, 'value' => null]]]), $version);
			$this->assertSame(RowWriteMessages::valueRefused(), $this->refusal($app, 'alice', self::UPDATE, ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => null]]]), $version);
			$this->assertSame([[], [], [5, 6]], [$app->callsOf('RowService', 'create'), $app->callsOf('RowService', 'updateSet'), $app->rowIds(1)], $version);
			$this->assertSame(10.0, $app->row(1, 5)['data'][12], $version);
			$plan = $this->preview($app, 'alice', self::UPDATE, ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 0]]]);
			$this->confirm($app, 'alice', self::UPDATE, ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 0]]], $plan);
			$this->assertSame(0.0, $app->row(1, 5)['data'][12], "$version zero written as asked");
		}
		$app = WriteScene::app('2.3.2');
		$arguments = ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => null]]];
		$this->confirm($app, 'alice', self::UPDATE, $arguments, $this->preview($app, 'alice', self::UPDATE, $arguments));
		$this->assertNull($app->row(1, 5)['data'][12], 'kept empty by the 2.3.2 mapper');
	}

	/**
	 * The fake stores what the pinned mappers store and answers what RowService held in memory: a null number reaching the
	 * sink before 2.3.2 would come back as null while 0 is stored, which is why it is refused before.
	 *
	 * @return void
	 */
	public function testTheFakeStoresWhatTheMapperStores(): void {
		$app = WriteScene::app('2.2.2');
		$service = (\Closure::bind(fn (string $class, string $uid): object => $this->resolve($class, $uid), $app, $app))('OCA\\Tables\\Service\\RowService', 'alice');
		$row = $service->create(1, null, [['columnId' => 11, 'value' => 'a'], ['columnId' => 12, 'value' => null]])->jsonSerialize();
		$this->assertSame([['columnId' => 11, 'value' => 'a'], ['columnId' => 12, 'value' => null]], array_values(array_filter($row['data'], static fn (array $c): bool => in_array($c['columnId'], [11, 12], true))));
		$this->assertSame(0.0, $app->row(1, $row['id'])['data'][12]);
	}

	/**
	 * A view column the saved sort alone names, hidden: a change of its schema between the plan and the confirmation gives
	 * a new plan, for a creation and for an update; the same state writes.
	 *
	 * @return void
	 */
	public function testAColumnOfTheSavedSortIsBound(): void {
		foreach ([[self::CREATE, ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'Sorted']]]], [self::UPDATE, ['view_id' => 41, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 25]]]]] as [$tool, $arguments]) {
			$app = self::sortedByHidden();
			$plan = $this->preview($app, 'alice', $tool, $arguments);
			$app->withColumn(['id' => 25, 'tableId' => 1, 'title' => 'Rank', 'textDefault' => 'later']);
			try {
				$this->confirm($app, 'alice', $tool, $arguments, $plan);
				$this->fail("$tool written after the sorted column changed");
			} catch (PlanChanged) {
				$this->assertSame([[], []], [$app->callsOf('RowService', 'create'), $app->callsOf('RowService', 'updateSet')], $tool);
			}
			$app = self::sortedByHidden();
			$this->confirm($app, 'alice', $tool, $arguments, $this->preview($app, 'alice', $tool, $arguments));
			$this->assertCount(1, [...$app->callsOf('RowService', 'create'), ...$app->callsOf('RowService', 'updateSet')], "$tool stable");
			$this->assertStringNotContainsString('Rank', json_encode($plan, JSON_THROW_ON_ERROR));
		}
	}

	/**
	 * The scene with column 25 Rank, hidden by view 41, which sorts by it and does not filter on it.
	 *
	 * @return FakeTablesWriteApp the app
	 */
	private static function sortedByHidden(): FakeTablesWriteApp {
		return WriteScene::app()->withColumn(['id' => 25, 'tableId' => 1, 'title' => 'Rank'])
			->withView(41, 1, [11, 12, 13, 15, 16, 21, 22], [22 => ['readonly' => true]], WriteScene::VIEW_FILTER, '[{"columnId":25,"mode":"ASC"}]');
	}

	/**
	 * The plan of a write.
	 *
	 * @param FakeTablesWriteApp $app the app
	 * @param string $uid the caller, also the session user
	 * @param string $tool the tool
	 * @param array<string, mixed> $arguments the arguments
	 * @return array<string, mixed> the plan
	 */
	private function preview(FakeTablesWriteApp $app, string $uid, string $tool, array $arguments): array {
		return TablesWriteWiring::flow($this, $app, $uid)->preview($tool, $arguments, $uid, $tool === self::UPDATE);
	}

	/**
	 * The confirmed write of a plan.
	 *
	 * @param FakeTablesWriteApp $app the app
	 * @param string $uid the caller, also the session user
	 * @param string $tool the tool
	 * @param array<string, mixed> $arguments the arguments
	 * @param array<string, mixed> $plan the approved plan
	 * @return array<string, mixed> the decoded answer
	 */
	private function confirm(FakeTablesWriteApp $app, string $uid, string $tool, array $arguments, array $plan): array {
		return $this->answer(TablesWriteWiring::flow($this, $app, $uid)->handle($tool, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]],
			$uid, $tool === self::UPDATE));
	}

	/**
	 * The message of a refused plan or confirmed call.
	 *
	 * @param FakeTablesWriteApp $app the app
	 * @param string $uid the caller
	 * @param string $tool the tool
	 * @param array<string, mixed> $arguments the arguments
	 * @param bool $confirmed whether to call the confirmed write instead of the plan
	 * @param string|null|false $session the session user, false for the caller
	 * @return string the message
	 */
	private function refusal(FakeTablesWriteApp $app, string $uid, string $tool, array $arguments, bool $confirmed = false, string|null|false $session = false): string {
		$flow = TablesWriteWiring::flow($this, $app, $session === false ? $uid : $session);
		try {
			$confirmed ? $flow->handle($tool, $arguments, $uid, $tool === self::UPDATE) : $flow->preview($tool, $arguments, $uid, $tool === self::UPDATE);
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('expected a refusal');
	}

	/**
	 * The decoded JSON of a tool result.
	 *
	 * @param array{content: list<array{type:string, text:string}>} $result the result
	 * @return array<string, mixed> the decoded answer
	 */
	private function answer(array $result): array {
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
