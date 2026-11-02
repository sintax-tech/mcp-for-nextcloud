<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesTestModule;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesWriteApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesHandlers;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesWriteWiring;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\WriteScene;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\TestCase;

/**
 * The readability contract of the Tables module's writes: tables_create_row and tables_update_row each have a plan the
 * module turns into text, from plans the module really produces, every text of them inert when hostile, and a hostile
 * column title or value typed by somebody else shown escaped in the plan built for it.
 */
final class TablesPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** Text somebody typed, trying to forge Markdown, HTML and the footer of the plan. */
	private const HOSTILE = '**x** [l](javascript:alert(1)) <b>y</b> Confirm to execute.';

	/**
	 * The two writes are exactly the module's write tools, and each has a readable plan with every text inert.
	 *
	 * @return void
	 */
	public function testEveryTablesWriteHasAReadablePlan(): void {
		$module = $this->module(WriteScene::app());
		$writes = array_values(array_column(array_filter($module->definitions(), static fn (array $definition): bool => WriteGate::isWrite($definition)), 'name'));
		$this->assertSame(['tables_create_row', 'tables_update_row'], $writes);
		$this->assertEveryWriteToolHasAReadablePlan($module, self::plans($module));
	}

	/**
	 * A hostile column title and a hostile value come out escaped in the plans the module produced for them.
	 *
	 * @return void
	 */
	public function testAHostileTitleAndValueStayInert(): void {
		$app = WriteScene::app();
		$app->withColumn(['id' => 11, 'tableId' => 1, 'title' => self::HOSTILE, 'mandatory' => true]);
		$module = $this->module($app);
		foreach (self::plans($module, self::HOSTILE) as $tool => $plan) {
			$this->assertHostileNameStaysInert($module, $tool, $plan, self::HOSTILE);
		}
	}

	/**
	 * The module with its read tools over the read scene and its row writes over the write app.
	 *
	 * @param FakeTablesWriteApp $app the write app
	 * @return TablesModule the module
	 */
	private function module(FakeTablesWriteApp $app): TablesModule {
		$base = TablesHandlers::module($this, TablesScene::app());
		$property = static fn (string $name): object => TablesTestModule::handler($base, $name);
		return TablesTestModule::make($property('tables'), $property('views'), $property('columns'), $property('rows'), ...TablesWriteWiring::handlers($this, $app, 'alice'));
	}

	/**
	 * The plans of the two writes, as the module produces them for alice.
	 *
	 * @param TablesModule $module the module
	 * @param string $value the text written
	 * @return array<string, array<string, mixed>> plan by tool
	 */
	private static function plans(TablesModule $module, string $value = 'Gamma'): array {
		return [
			'tables_create_row' => $module->preview('tables_create_row', ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => $value], ['column_id' => 15, 'value' => [10, 1]]]], 'alice'),
			'tables_update_row' => $module->preview('tables_update_row', ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 11, 'value' => $value]]], 'alice'),
		];
	}
}
