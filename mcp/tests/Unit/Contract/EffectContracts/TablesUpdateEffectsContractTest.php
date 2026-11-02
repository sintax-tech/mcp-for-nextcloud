<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesWriteApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesHandlers;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesWriteWiring;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\WriteScene;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Tables\Creation\CreateRowHandler;
use OCA\Mcp\Modules\Tables\Editing\UpdateRowHandler;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Modules\Tables\Writing\CellValueDomain;
use OCA\Mcp\Modules\Tables\Writing\RelationWriteGate;
use OCA\Mcp\Modules\Tables\Writing\RowCurrentState;
use OCA\Mcp\Modules\Tables\Writing\RowWriteColumns;
use OCA\Mcp\Modules\Tables\Writing\RowWriteFlow;
use OCA\Mcp\Modules\Tables\Writing\RowWritePlan;
use OCA\Mcp\Modules\Tables\Writing\RowWritePreflight;
use OCA\Mcp\Modules\Tables\Writing\RowWriter;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCA\Mcp\Modules\Tables\Writing\ViewDefaultsGate;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of tables_update_row (RE-01): changing two cells of a row alice owns, frozen through the endpoint with
 * a fake Tables app whose stored rows and RowService::updateSet() calls are the observable effect.
 */
final class TablesUpdateEffectsContractTest extends TestCase {
	/** The row of the scene the flow changes (Alpha, 10, Secret "ok"). */
	private const ROW = 5;
	/** The cells the plan shows and the confirmation must write, all of them. */
	private const CELLS = [['column_id' => 11, 'value' => 'Alpha revisado'], ['column_id' => 12, 'value' => 25]];

	/** The Tables app of the scene, persistent across requests. */
	private FakeTablesWriteApp $app;
	/** The endpoint the flow goes through. */
	private EffectsGate $gate;

	/** @return void builds the Tables scene and the endpoint */
	protected function setUp(): void {
		$this->app = WriteScene::app();
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/**
	 * tables_update_row on an owned table: plan, strict confirm, PlanChanged when another editor changed the row,
	 * effect and every refusal.
	 *
	 * @return void
	 */
	public function testUpdatingARow(): void {
		$flow = (new CriticalFlow('update-row', 'tables_update_row', 'tables', 'edit',
			['table_id' => WriteScene::TABLE, 'row_id' => self::ROW, 'cells' => self::CELLS],
			fn (): array => $this->state()))
			// Another editor changed the Amount of the row after the plan: the plan showed the old value as "before".
			->changing(function (): void {
				$this->app->withRow(WriteScene::TABLE, self::ROW, [11 => 'Alpha', 12 => 40.0, 14 => 'ok'], '2026-10-20 09:00:00');
			})
			->withoutVisibility('Tables rows live in the Tables database, not in Files: no system tag or excluded folder can hide a table from MCP');
		(new FlowRunner($this, $this->gate, 'tables'))->run($flow);
		$this->assertTheConfirmedWriteIsThePlan();
	}

	/** @return void a shared table asks for its additional confirmation and keeps every row until then */
	public function testSharedUpdateNeedsConfirmShared(): void {
		$this->app->withTable(WriteScene::TABLE, 'carol')->grant('alice', 'table', WriteScene::TABLE, 'read', 'update');
		$t = new EffectTranscript($this, 'tables.update-shared');
		$args = ['table_id' => WriteScene::TABLE, 'row_id' => self::ROW, 'cells' => self::CELLS];
		$plan = $this->gate->call('tables_update_row', $args)['body'];
		$args += ['confirm' => true, 'plan_state' => $plan->result->structuredContent->plan_state];
		$before = $this->state();
		$out = $this->gate->call('tables_update_row', $args);
		$t->record('shared-unconfirmed', $args, $out, $this->state());
		$this->assertEquals($before, $this->state());
		$this->assertTrue(json_decode($out['body']->result->content[0]->text ?? 'null', true)['requiresConfirmation'] ?? false, json_encode($out));
		$args['confirm_shared'] = true;
		$out = $this->gate->call('tables_update_row', $args);
		$t->record('shared-confirmed', $args, $out, $this->state());
		$this->assertFalse($out['body']->result->isError ?? false, json_encode($out));
		$this->assertNotEquals($before, $this->state());
		$t->assertGolden();
	}

	/**
	 * The key effect facts, outside the golden: the one confirmed update sent every planned cell to RowService, and the
	 * stored row holds exactly the planned values, the cells it was not asked to touch kept.
	 *
	 * @return void
	 */
	private function assertTheConfirmedWriteIsThePlan(): void {
		$calls = $this->app->callsOf('RowService', 'updateSet');
		$this->assertNotSame([], $calls, 'the confirmed update must reach RowService::updateSet()');
		$this->assertSame([self::ROW, null, [['columnId' => 11, 'value' => 'Alpha revisado'], ['columnId' => 12, 'value' => 25]], EffectsGate::USER,
			WriteScene::TABLE], $calls[0], 'the first confirmed update writes every planned cell, as planned');
		$this->assertSame([11 => 'Alpha revisado', 12 => 25.0, 14 => 'ok'], $this->app->row(WriteScene::TABLE, self::ROW)['data'],
			'the stored row holds the planned values and keeps the hidden Secret');
		$this->assertSame([], $this->app->callsOf('RowService', 'create'), 'an update never creates a row');
	}

	/**
	 * The module of one request: the read tools as TablesHandlers wires them, the row writes over the write app with the
	 * plan state under the gate's configuration, every collaborator of the write flow present.
	 *
	 * @return TablesModule the module
	 */
	private function module(): TablesModule {
		$base = TablesHandlers::module($this, TablesScene::app());
		$property = static fn (string $name): object => (fn (): object => $this->{$name})->call($base);
		$flow = $this->flow();
		return new TablesModule($property('tables'), $property('views'), $property('columns'), $property('rows'),
			new CreateRowHandler($flow), new UpdateRowHandler($flow));
	}

	/**
	 * The row write flow as {@see TablesWriteWiring::flow()} builds it, with alice in the session and the plan state of
	 * the gate.
	 *
	 * @return RowWriteFlow the flow
	 */
	private function flow(): RowWriteFlow {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(EffectsGate::USER);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = new TablesSessionGuard($session);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $id): bool => in_array($id, TablesWriteWiring::USERS, true));
		$users->method('get')->willReturnCallback(fn (string $id): ?IUser => in_array($id, TablesWriteWiring::USERS, true) ? $this->createMock(IUser::class) : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $id): bool => in_array($id, TablesWriteWiring::GROUPS, true));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$preflight = new RowWritePreflight($guard, new RowWriteColumns($this->app), new CellValueDomain(new UsergroupWhitelist($users, $groups, $apps)),
			new RelationWriteGate($this->app), new ViewDefaultsGate($this->app), new RowCurrentState($this->app, $guard));
		return new RowWriteFlow($preflight, new RowWritePlan(new PlanState($this->gate->config()), TablesScene::links($this)),
			new RowWriter($this->app, $guard));
	}

	/**
	 * @return array{rows: array<int, array{data: array<int, mixed>, lastEditAt: string}>, updates: list<list<mixed>>, creates: int}
	 *     the stored rows of the table, every RowService::updateSet() call with its arguments and the creations
	 */
	private function state(): array {
		$rows = [];
		foreach ($this->app->rowIds(WriteScene::TABLE) as $id) {
			$rows[$id] = $this->app->row(WriteScene::TABLE, $id);
		}
		return ['rows' => $rows, 'updates' => $this->app->callsOf('RowService', 'updateSet'),
			'creates' => count($this->app->callsOf('RowService', 'create'))];
	}
}
