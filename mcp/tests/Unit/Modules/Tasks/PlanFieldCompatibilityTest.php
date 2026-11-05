<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

use OCA\Mcp\Modules\Tasks\Presentation\TasksPlanRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanFieldCompatibilityTest extends TestCase {
	protected function setUp(): void {
		\OCA\Mcp\L10n\Translator::reset();
	}

	public static function writes(): array {
		return [['tasks_create_task'], ['tasks_edit_task']];
	}

	#[DataProvider('writes')]
	public function testCreateAndEditNowShowTheirCompletionDate(string $tool): void {
		$plan = ['calendar' => ['name' => 'Tasks'], 'before' => ['summary' => 'Work', 'percentComplete' => 0],
			'after' => ['summary' => 'Work', 'percentComplete' => 100], 'timezone' => 'UTC'];
		$renderer = new TasksPlanRenderer();
		$withoutDate = $renderer->render($tool, $plan);
		self::assertNotNull($withoutDate);
		$plan['after']['completed'] = '2026-10-01';
		self::assertStringNotContainsString('Completed at', $withoutDate);
		self::assertStringContainsString('Completed at', (string) $renderer->render($tool, $plan));
	}
	public function testAProgressEditKeepsItsExistingConfirmationText(): void {
		$plan = ['calendar' => ['name' => 'Tasks'], 'before' => ['summary' => 'Work', 'percentComplete' => 0],
			'after' => ['summary' => 'Work', 'percentComplete' => 40], 'timezone' => 'UTC'];
		self::assertSame("Editing the task **Work** in the calendar *Tasks*.\n- Progress: 0% → 40%",
			(new TasksPlanRenderer())->render('tasks_edit_task', $plan));
	}

}
