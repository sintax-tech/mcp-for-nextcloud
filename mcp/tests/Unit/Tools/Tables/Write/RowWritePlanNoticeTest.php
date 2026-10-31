<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanRenderer;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\Tables\Handler\UpdateRowHandler;
use OCA\Mcp\Tools\ToolModule;
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
	 * The person's Markdown, body from the row renderer and warnings from the common envelope.
	 *
	 * @param array<string, mixed> $plan the plan
	 * @return string the rendered answer
	 */
	private static function text(array $plan): string {
		return PlanRenderer::render(new class implements ToolModule, RendersPlans {
			public function definitions(): array {
				return [];
			}

			public function call(string $name, array $arguments, string $userId): array {
				return ['content' => []];
			}

			public function renderPlan(string $tool, array $plan): ?string {
				return (new RowWritePlanRenderer())->render($plan);
			}
		}, UpdateRowHandler::TOOL, $plan);
	}
}
