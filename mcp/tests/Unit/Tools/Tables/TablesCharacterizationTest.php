<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesTestModule;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Modules\Tables\Writing\CellValueDomain;
use OCA\Mcp\Modules\Tables\Writing\TextCellValue;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\{TablesHandlers, TablesScene, TablesWriteWiring, RowsScene, WriteScene};
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Tests\Unit\Contract\Oracle\{DiscoveryHarness, DiscoveryScenario};

/** Frozen observations at the pure move, shared by isolated differential runs after each split. */
final class TablesCharacterizationTest extends TestCase {
	public function testTheSplitPreservesTheCharacterizedAnswers(): void {
		$report = [];
		$base = TablesHandlers::module($this, TablesScene::app());
		$report['definitions'] = array_map(static fn (array $d): array => array_replace(array_fill_keys(['name', 'description', 'module', 'operation', 'app', 'inputSchema'], null), $d), $base->definitions());
		$report['notes'] = $base->guideNotes();
		foreach (DiscoveryScenario::all() as $scenario) {
			$harness = new DiscoveryHarness($this, $scenario);
			$protocol = (fn () => $this->protocol)->call($harness);
			$registry = (fn () => $this->tools)->call($protocol);
			$tables = static fn (array $definitions): array => array_values(array_filter($definitions,
				static fn (array $definition): bool => str_starts_with($definition['name'], 'tables_')));
			$report['registry'][$scenario->name] = [
				'definitions' => $tables($registry->definitions(DiscoveryHarness::USER)),
				'list' => $tables($registry->list(DiscoveryHarness::USER)),
			];
		}
		foreach (['alice', 'bob', 'carol', 'dave', 'erin', 'frank'] as $uid) {
			$app = TablesScene::app();
			$module = TablesHandlers::module($this, $app, $uid);
			foreach (['tables_list_tables' => ['limit' => 1], 'tables_list_views' => [], 'tables_list_columns' => ['view_id' => 21]] as $tool => $args) {
				$report['read'][$uid][$tool] = self::observe(fn () => $module->call($tool, $args, $uid));
			}
			$report['read'][$uid]['journal'] = $app->journal->getArrayCopy();
			$report['read'][$uid]['writes'] = $app->writes;
		}
		foreach (['alice', 'bob', 'dave'] as $uid) {
			$app = RowsScene::app();
			$module = TablesHandlers::module($this, $app, $uid);
			foreach ([['table_id' => 1, 'limit' => 2], ['view_id' => 31, 'limit' => 1], ['table_id' => 1, 'view_id' => 31], []] as $i => $args) {
				$report['rows'][$uid][$i] = self::observe(fn () => $module->call('tables_list_rows', $args, $uid));
			}
		}
		foreach (['create' => ['view_id' => 41, 'cells' => [['column_id' => 11, 'value' => 'Gamma'], ['column_id' => 15, 'value' => [10, 1]]]],
			'update' => ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 11, 'value' => 'Changed']]]] as $verb => $args) {
			foreach (['alice', 'carol', 'dave'] as $uid) {
				foreach (['ok', 'refuse', 'failAfter'] as $sink) {
					$app = WriteScene::app();
					$app->actor = $uid;
					$app->sink = $sink;
					$module = $this->writes($base, $app, $uid);
					$tool = 'tables_' . $verb . '_row';
					$key = "$verb/$uid/$sink";
					$report['write'][$key]['preview'] = self::observe(fn () => $module->preview($tool, $args, $uid));
					$report['write'][$key]['before'] = [$app->rowIds(1), $app->row(1, 5), $app->journal->getArrayCopy()];
					$plan = $report['write'][$key]['preview']['result'] ?? [];
					$report['write'][$key]['render'] = self::observe(fn () => $module->renderPlan($tool, $plan));
					$report['write'][$key]['missingState'] = self::observe(fn () => $module->call($tool, $args + ['confirm' => true], $uid));
					$report['write'][$key]['execute'] = self::observe(fn () => $module->call($tool, $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state'] ?? ''], $uid));
					$report['write'][$key]['after'] = [$app->rowIds(1), $app->row(1, 5), $app->row(1, 1002), $app->journal->getArrayCopy()];
				}
			}
		}
		$flow = TablesWriteWiring::flow($this, WriteScene::app(), 'alice');
		$preflight = (fn () => $this->preflight)->call($flow);
		$domain = (fn () => $this->domain)->call($preflight);
		foreach (['1.0.0', '2.0.0', '2.2.2', '2.3.2'] as $version) {
			foreach ([null, ['url' => 'https://example.org/path', 'title' => 'Title'], ['url' => 'javascript:alert(1)'], ['url' => 'https://example.org', 'title' => 'x (http://injected)'], ['url' => 'https://example.org', 'extra' => 'x']] as $i => $value) {
				$report['link'][$version][$i] = self::observe(fn () => $domain->cell(['type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url'], $value, $version, 'alice'));
			}
			foreach ([null, 2, 3] as $i => $value) {
				$report['selection'][$version][$i] = self::observe(fn () => $domain->cell(['type' => 'selection', 'subtype' => '', 'selectionOptions' => [['id' => 2]]], $value, $version, 'alice'));
			}
		}
		foreach ([null, 'abc', 'áβ𝄞', 'abcd', 42] as $i => $value) {
			$report['text'][$i] = self::observe(fn () => $domain->cell(
				['type' => 'text', 'subtype' => 'line', 'textMaxLength' => 3], $value, '2.3.2', 'alice'));
		}
		$text = class_exists(TextCellValue::class) ? TextCellValue::class : CellValueDomain::class;
		$report['textBoundaries'] = [
			'max' => $text::MAX_TEXT,
			'atCap' => $text::fitsText([], str_repeat('x', 100000)),
			'overCap' => $text::fitsText([], str_repeat('x', 100001)),
		];
		$json = json_encode(self::normalize($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RE17_FREEZE') === '1') {
			file_put_contents(__DIR__ . '/tables-characterization.json', $json);
		}
		self::assertSame(file_get_contents(__DIR__ . '/tables-characterization.json'), $json);
	}

	private function writes(TablesModule $base, object $app, string $uid): TablesModule {
		$property = static fn (string $name): object => TablesTestModule::handler($base, $name);
		return TablesTestModule::make($property('tables'), $property('views'), $property('columns'), $property('rows'), ...TablesWriteWiring::handlers($this, $app, $uid));
	}

	private static function normalize(mixed $value): mixed {
		if ($value instanceof \OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesDouble) {
			return ['double' => (fn () => $this->label)->call($value)];
		}
		return is_array($value) ? array_map(self::normalize(...), $value) : $value;
	}

	private static function observe(callable $call): array {
		try {
			return ['result' => $call()];
		} catch (\Throwable $e) {
			return ['error' => get_class($e), 'message' => $e->getMessage()];
		}
	}
}
