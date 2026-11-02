<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesTestModule;
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
 * Effect contract of the Tables module (RE-01): creating a row in a table alice owns, frozen through the endpoint with
 * a fake Tables app whose stored rows and RowService::create() calls are the observable effect.
 */
final class TablesEffectsContractTest extends TestCase {
	private FakeTablesWriteApp $app;
	private EffectsGate $gate;

	/** @return void builds the Tables scene and the endpoint */
	protected function setUp(): void {
		$this->app = WriteScene::app();
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/**
	 * The module of one request: the read tools as TablesHandlers wires them, the row writes over the write app with the
	 * plan state under the gate's configuration, every collaborator of the write flow present.
	 *
	 * @return TablesModule the module
	 */
	private function module(): TablesModule {
		$base = TablesHandlers::module($this, TablesScene::app());
		$property = static fn (string $name): object => TablesTestModule::handler($base, $name);
		$flow = $this->flow();
		return TablesTestModule::make($property('tables'), $property('views'), $property('columns'), $property('rows'),
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

	/** @return void tables_create_row in an owned table: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testCreatingARow(): void {
		$flow = (new CriticalFlow('create-row', 'tables_create_row', 'tables', 'create',
			['table_id' => WriteScene::TABLE, 'cells' => [['column_id' => 11, 'value' => 'Gamma'], ['column_id' => 12, 'value' => 25]]],
			fn (): array => $this->state()))
			// The owner raised the limit of Amount: the value is still valid, but the plan showed the old limit.
			->changing(function (): void {
				$this->app->withColumn(['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => '', 'numberMin' => 0, 'numberMax' => 2000]);
			})
			->withoutVisibility('Tables rows live in the Tables database, not in Files: no system tag or excluded folder can hide a table from MCP');
		(new FlowRunner($this, $this->gate, 'tables'))->run($flow);
	}

	/** @return array{rows: array<int, array<int, mixed>>, creates: int} the stored rows of the table and the RowService creations */
	private function state(): array {
		$rows = [];
		foreach ($this->app->rowIds(WriteScene::TABLE) as $id) {
			$rows[$id] = $this->app->row(WriteScene::TABLE, $id)['data'];
		}
		return ['rows' => $rows, 'creates' => count($this->app->callsOf('RowService', 'create'))];
	}
}
