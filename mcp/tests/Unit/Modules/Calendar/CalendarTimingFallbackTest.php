<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanRenderer;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarPlanRenderer;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarTimingFormatter;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The final confirmation text retains dates and complete reminders without an optional extension. */
final class CalendarTimingFallbackTest extends CalendarTestCase {
	/** @return iterable<string, array{string, string, bool, bool, bool}> languages, formatter modes and event shapes */
	public static function modes(): iterable {
		foreach (CalendarAlarmsTest::locales() as $name => [$locale, $fragment]) {
			foreach ([true, false] as $useIntl) {
				foreach ([true, false] as $allDay) {
					foreach ([true, false] as $alarms) {
						yield $name . '-' . (int)$useIntl . '-' . (int)$allDay . '-' . (int)$alarms => [$locale, $fragment, $useIntl, $allDay, $alarms];
					}
				}
			}
		}
	}

	/**
	 * @param string $locale request language
	 * @param string $fragment localized reminder label
	 * @param bool $useIntl allow Intl or force the extension-independent formatter
	 * @param bool $allDay civil-day versus timed event
	 * @param bool $alarms include the new feature or exercise the accepted legacy defect fix
	 * @return void create and update expose the actual readable body, never a generic nested-field dump
	 */
	#[DataProvider('modes')]
	public function testFinalCreateAndUpdateText(string $locale, string $fragment, bool $useIntl, bool $allDay, bool $alarms): void {
		Translator::use(new JsonL10n($locale));
		$renderer = new CalendarPlanRenderer($locale, new CalendarTimingFormatter($useIntl));
		$module = $this->createMockForIntersectionOfInterfaces([ToolModule::class, RendersPlans::class]);
		$module->method('renderPlan')->willReturnCallback(fn ($tool, $plan) => $renderer->renderPlan($tool, $plan));
		$extra = $alarms ? ['alarms' => [
			['minutesBefore' => 15, 'description' => '**literal**'],
			['minutesBefore' => 60, 'description' => 'Second reminder'],
		]] : [];
		$dates = $allDay ? ['start' => '2026-12-01', 'end' => '2026-12-02', 'allDay' => true]
			: ['start' => '2026-12-01T13:00:00Z', 'end' => '2026-12-01T14:00:00Z', 'timeZone' => 'America/Sao_Paulo'];
		$body = $allDay ? "UID:timing\nSUMMARY:Before\nDTSTART;VALUE=DATE:20261130\nDTEND;VALUE=DATE:20261201"
			: "UID:timing\nSUMMARY:Before\nDTSTART:20261201T120000Z\nDTEND:20261201T130000Z";
		$this->store->addObject(1, 'timing.ics', self::ics($body));
		foreach (['calendar_create_event', 'calendar_update_event'] as $tool) {
			$args = ['calendar' => self::PERSONAL, 'summary' => 'Meeting'] + $dates + $extra;
			if ($tool === 'calendar_update_event') {
				$args['uid'] = 'timing';
			}
			$plan = $this->module->preview($tool, $args, 'alice');
			$text = PlanRenderer::render($module, $tool, $plan);
			self::assertNotNull($renderer->renderPlan($tool, $plan));
			self::assertStringContainsString('Meeting', $text);
			self::assertStringContainsString(Translator::t('- When: %s', ['']), $text);
			self::assertStringNotContainsString('Alarm change:', $text);
			if (!$useIntl || !class_exists(\IntlDateFormatter::class)) {
				self::assertStringContainsString('2026-12-01', $text);
				if (!$allDay) {
					self::assertStringContainsString('10:00–11:00', $text);
				}
			}
			if ($alarms) {
				self::assertStringContainsString($fragment, $text);
				self::assertStringContainsString('\*\*literal\*\*', $text);
				self::assertStringContainsString('Second reminder', $text);
				self::assertStringContainsString('DISPLAY: 15 ', $text);
				self::assertStringContainsString('DISPLAY: 60 ', $text);
			} else {
				self::assertStringNotContainsString($fragment, $text);
			}
		}
		$this->assertNoWrites();
	}

	/** @return void fallback dates preserve exclusive civil ends, multi-day ranges and account zone */
	public function testFallbackIntervals(): void {
		$format = new CalendarTimingFormatter(false);
		self::assertSame('2026-12-01 – 2026-12-03', $format->format('2026-12-01', '2026-12-04', true, null, null, 'en'));
		self::assertSame('2026-12-01, 23:00 – 2026-12-02, 01:00 (America/Sao_Paulo)',
			$format->format('2026-12-02T02:00:00Z', '2026-12-02T04:00:00Z', false, 'America/Sao_Paulo', 'UTC', 'en'));
		self::assertSame('2026-12-01, 10:00', $format->format('2026-12-01T13:00:00Z', '2026-12-01T13:00:00Z', false, null, 'America/Sao_Paulo', 'en'));
	}
}
