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
use OCA\Mcp\Modules\Calendar\Presentation\AlarmMessages;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Reminder consequences belong to the common envelope, including a failing custom body. */
final class AlarmWarningsTest extends CalendarTestCase {
	/** @return iterable<string, array{bool}> both generic fallback causes */
	public static function fallbacks(): iterable {
		yield 'null body' => [false];
		yield 'throwing body' => [true];
	}

	/**
	 * @param bool $throws custom renderer failure rather than an explicit fallback
	 * @return void delivery, sharing and overrides warnings survive either rendering path
	 */
	#[DataProvider('fallbacks')]
	public function testWarningsSurviveGenericFallback(bool $throws): void {
		Translator::reset();
		$module = $this->createMockForIntersectionOfInterfaces([ToolModule::class, RendersPlans::class]);
		$render = $module->method('renderPlan');
		$throws ? $render->willThrowException(new \Error('renderer unavailable')) : $render->willReturn(null);
		$create = $this->module->preview('calendar_create_event', [
			'calendar' => self::TEAM, 'summary' => 'Shared reminder', 'start' => '2026-12-01', 'end' => '2026-12-02',
			'allDay' => true, 'alarms' => [['minutesBefore' => 15]],
		], 'alice');
		$text = PlanRenderer::render($module, 'calendar_create_event', $create);
		self::assertStringContainsString('### Warnings', $text);
		self::assertStringContainsString('notification delivery is not verified', $text);
		self::assertStringContainsString('shared event', $text);
		self::assertSame(1, substr_count($text, 'notification delivery is not verified'));
		$override = "BEGIN:VEVENT\nUID:series\nRECURRENCE-ID:20261202T120000Z\nDTSTART:20261202T140000Z\nDTEND:20261202T150000Z\nEND:VEVENT";
		$this->store->addObject(1, 'series.ics', self::ics("UID:series\nDTSTART:20261201T120000Z\nDTEND:20261201T130000Z\nRRULE:FREQ=DAILY", $override));
		$update = $this->module->preview('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'series', 'alarms' => []], 'alice');
		$text = PlanRenderer::render($module, 'calendar_update_event', $update);
		self::assertStringContainsString('1 individually changed occurrence keeps', $text);
		self::assertStringContainsString('does not receive these reminders', $text);
		$human = PlanRenderer::render($this->module, 'calendar_update_event', $update);
		self::assertSame(1, substr_count($human, 'does not receive these reminders'));
		$this->assertNoWrites();
	}

	/** @return iterable<string, array{string, string, string, string}> translated count and operation fragments */
	public static function languages(): iterable {
		yield 'en' => ['en', 'occurrence keeps', 'occurrences keep', 'Do not repeat the change'];
		yield 'pt_BR' => ['pt_BR', 'ocorrência alterada', 'ocorrências alteradas', 'Não repita a alteração'];
		yield 'es' => ['es', 'ocurrencia modificada', 'ocurrencias modificadas', 'No repita el cambio'];
		yield 'de' => ['de', 'Vorkommen behält', 'Vorkommen behalten', 'Wiederholen Sie die Änderung nicht'];
	}

	/**
	 * @param string $locale required language
	 * @param string $singular expected singular phrase
	 * @param string $plural expected plural phrase
	 * @param string $update expected update advice
	 * @return void localized singular/plural and update-specific retry advice
	 */
	#[DataProvider('languages')]
	public function testCountAndOperationMessages(string $locale, string $singular, string $plural, string $update): void {
		$json = new JsonL10n($locale);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $parameters) => $json->t($text, $parameters));
		// JsonL10n's plural stub assumes three forms for every non-English locale.
		// Model German's two forms here without changing the shared test helper.
		$dictionary = $locale === 'en' ? [] : json_decode(file_get_contents(__DIR__ . '/../../../../l10n/' . $locale . '.json'), true)['translations'];
		$l10n->method('n')->willReturnCallback(static function ($singular, $plural, $count, $parameters) use ($locale, $dictionary): string {
			$forms = $dictionary['_' . $singular . '_::_' . $plural . '_'] ?? [$singular, $plural];
			$index = $count === 1 ? 0 : (in_array($locale, ['es', 'pt_BR'], true) ? 2 : 1);
			return vsprintf(str_replace('%n', (string)$count, $forms[$index]), $parameters);
		});
		Translator::use($l10n);
		self::assertStringContainsString($singular, AlarmMessages::overrides(1));
		self::assertStringContainsString($plural, AlarmMessages::overrides(2));
		foreach (['partial', 'uncertain'] as $status) {
			self::assertStringContainsString($update, AlarmMessages::receipt($status, 'event', false));
			self::assertNotSame(AlarmMessages::receipt($status, 'event', true), AlarmMessages::receipt($status, 'event', false));
		}
	}
}
