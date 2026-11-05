<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Tasks\Presentation\TaskPlanValues;
use OCA\Mcp\Modules\Tasks\Presentation\TaskStatusPlanText;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;

final class TaskStatusPlanTest extends TestCase {
	protected function tearDown(): void { Translator::reset(); }

	public function testCompletionDateAdditionRemovalAndChangeUseThePlanTimezone(): void {
		$zone = new DateTimeZone('America/Sao_Paulo');
		$helper = new TaskStatusPlanText();
		$before = ['status' => 'IN-PROCESS'];
		$after = ['status' => 'COMPLETED', 'completed' => '2026-10-01T12:00:00Z'];
		$date = class_exists(\IntlDateFormatter::class) ? (new TaskPlanValues())->value($after['completed'], 'completed', $zone) : '2026-10-01 09:00';
		self::assertContains('- Completed at: ' . $date, $helper->create([], $after, $zone));
		self::assertContains('- Completed at: empty → ' . $date, $helper->edit([], $before, $after, $zone));
		self::assertContains('- Completed at: ' . $date . ' → empty', $helper->edit([], $after, $before, $zone));
		$changed = $after; $changed['completed'] = '2026-10-01T12:00:30Z';
		self::assertCount(1, $helper->edit([], $after, $changed, $zone), 'even a change within the displayed minute is reported');
		$cancelled = $after; $cancelled['status'] = 'CANCELLED';
		self::assertSame(['- Status: completed → cancelled'], $helper->edit([], $after, $cancelled, $zone));
		self::assertSame([], $helper->edit([], $after, ['status' => 'completed', 'completed' => $after['completed']], $zone));
	}

	public function testDefaultCreateStatusIsOmittedAndOtherStatusesAreTranslated(): void {
		$helper = new TaskStatusPlanText(); $zone = new DateTimeZone('UTC');
		foreach (['pt_BR' => 'cancelada', 'es' => 'cancelada', 'de' => 'abgebrochen'] as $locale => $word) {
			Translator::use(new JsonL10n($locale));
			self::assertSame([], $helper->create([], ['status' => 'NEEDS-ACTION'], $zone));
			self::assertStringContainsString($word, implode('\n', $helper->create([], ['status' => 'CANCELLED'], $zone)));
		}
	}
}
