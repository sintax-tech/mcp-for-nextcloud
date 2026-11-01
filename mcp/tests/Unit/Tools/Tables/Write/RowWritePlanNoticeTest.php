<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\Tables\Handler\UpdateRowHandler;
use OCA\Mcp\Tools\Tables\Write\RowWritePlanRenderer;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesWriteWiring;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\WriteScene;
use PHPUnit\Framework\TestCase;

/**
 * A column numberMax that moves between the plan and the confirmation is named in the warning text.
 */
final class RowWritePlanNoticeTest extends TestCase {
	/**
	 * The rendered answer is not the approved plan: it carries a warning that cites numberMax.
	 *
	 * @return void
	 */
	public function testAChangedColumnLimitIsNamedInTheWarning(): void {
		$app = WriteScene::app();
		$arguments = ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 20]]];
		$flow = TablesWriteWiring::flow($this, $app, 'alice');
		$plan = $flow->preview(UpdateRowHandler::TOOL, $arguments, 'alice', true);
		$before = self::text($plan);
		self::assertStringNotContainsString('### ', $before);
		$app->withColumn(['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => '', 'numberMin' => 0, 'numberMax' => 2000]);
		try {
			$flow->handle(UpdateRowHandler::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice', true);
			$this->fail('the old column limit was confirmed');
		} catch (PlanChanged $changed) {
			$message = $changed->plan['warnings'][0]['message'];
			$this->assertNotSame('', $message);
			$this->assertStringContainsString('numberMax', $message);
			$this->assertStringContainsString('12=2000', $message);
			$text = self::text($changed->plan);
			$this->assertStringContainsString('### ', $text);
			$this->assertStringContainsString('Warnings', $text);
			$this->assertStringContainsString('numberMax', $text);
			$this->assertStringContainsString('2000', $text);
			$this->assertNotSame($before, $text);
			$this->assertSame([], $app->callsOf('RowService', 'updateSet'));
		}
	}

	/**
	 * A numberMax of 2e-9 is not printed as 0, and the same text keeps more than eight decimal places.
	 *
	 * @return void
	 */
	public function testATinyOrLongNumberMaxStaysDistinct(): void {
		$tiny = $this->changedLimit(1e-9, 2e-9, 0);
		$this->assertMatchesRegularExpression('/12=(?:2(?:\.0)?[eE]-0?9|0\.000000002)/', $tiny['warnings'][0]['message']);
		$this->assertMatchesRegularExpression('/12=(?:2(?:\.0)?[eE]-0?9|0\.000000002)/', $tiny['text']);
		$long = 1.2345678912345;
		$rounded = rtrim(rtrim(sprintf('%.8F', $long), '0'), '.');
		$precise = $this->changedLimit(1.0, $long, 0);
		$this->assertNotSame($rounded, json_encode($long));
		$this->assertStringContainsString('12=' . json_encode($long), $precise['warnings'][0]['message']);
		$this->assertStringContainsString('12=' . json_encode($long), $precise['text']);
		$this->assertStringNotContainsString('12=' . $rounded . ',', $precise['warnings'][0]['message'] . ',');
	}

	/**
	 * The last numeric column stays visible when the single warning is longer than the envelope cut.
	 *
	 * @return void
	 */
	public function testTheLastChangedNumberMaxStaysVisible(): void {
		$app = WriteScene::app();
		for ($id = 40; $id <= 90; $id++) {
			$app->withColumn(['id' => $id, 'tableId' => 1, 'title' => 'Number ' . $id, 'type' => 'number', 'subtype' => '', 'numberMax' => 1000]);
		}
		$arguments = ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => 20]]];
		$flow = TablesWriteWiring::flow($this, $app, 'alice');
		$plan = $flow->preview(UpdateRowHandler::TOOL, $arguments, 'alice', true);
		$app->withColumn(['id' => 90, 'tableId' => 1, 'title' => 'Number 90', 'type' => 'number', 'subtype' => '', 'numberMax' => 2000]);
		try {
			$flow->handle(UpdateRowHandler::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice', true);
			$this->fail('the old column limit was confirmed');
		} catch (PlanChanged $changed) {
			$this->assertStringContainsString('90=2000', $changed->plan['warnings'][0]['message']);
			$this->assertStringContainsString('90=2000', self::text($changed->plan));
			$this->assertStringContainsString('90=2000', (new RowWritePlanRenderer())->render($changed->plan) ?? '');
			$this->assertSame([], $app->callsOf('RowService', 'updateSet'));
		}
	}

	/**
	 * Confirms a row write after numberMax of column 12 moves.
	 *
	 * @param float $from the limit the approved plan bound
	 * @param float $to the limit found at confirmation
	 * @param int|float $value the cell value
	 * @return array{warnings: array<int, array<string, mixed>>, text: string} the new plan's warning and rendered answer
	 */
	private function changedLimit(float $from, float $to, int|float $value): array {
		$app = WriteScene::app();
		$column = ['id' => 12, 'tableId' => 1, 'title' => 'Amount', 'type' => 'number', 'subtype' => '', 'numberMin' => 0, 'numberMax' => $from];
		$app->withColumn($column);
		$arguments = ['table_id' => 1, 'row_id' => 5, 'cells' => [['column_id' => 12, 'value' => $value]]];
		$flow = TablesWriteWiring::flow($this, $app, 'alice');
		$plan = $flow->preview(UpdateRowHandler::TOOL, $arguments, 'alice', true);
		$column['numberMax'] = $to;
		$app->withColumn($column);
		try {
			$flow->handle(UpdateRowHandler::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice', true);
			$this->fail('the old column limit was confirmed');
		} catch (PlanChanged $changed) {
			$this->assertSame([], $app->callsOf('RowService', 'updateSet'));
			return ['warnings' => $changed->plan['warnings'], 'text' => self::text($changed->plan)];
		}
	}

	/**
	 * The person's Markdown, body from the row renderer and warnings from the common envelope.
	 *
	 * @param array<string, mixed> $plan the plan
	 * @return string the rendered answer
	 */
	private static function text(array $plan): string {
		return PlanRenderer::render(new class implements ToolModule, RendersPlans {
			/**
			 * No tool definition. This stand-in only renders.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function definitions(): array {
				return [];
			}

			/**
			 * Unused. The test never calls a tool through this module.
			 *
			 * @param string $name Tool name.
			 * @param array<string, mixed> $arguments Call arguments.
			 * @param string $userId Actor.
			 * @return array{content: list<array{type: string, text: string}>} Empty content.
			 */
			public function call(string $name, array $arguments, string $userId): array {
				return ['content' => []];
			}

			/**
			 * The row-write body, including one line per numeric limit.
			 *
			 * @param string $tool Tool name.
			 * @param array<string, mixed> $plan Structured plan.
			 * @return string|null Readable body.
			 */
			public function renderPlan(string $tool, array $plan): ?string {
				return (new RowWritePlanRenderer())->render($plan);
			}
		}, UpdateRowHandler::TOOL, $plan);
	}
}
