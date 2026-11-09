<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;

/** Collision warnings remain readable when the optional Intl extension is unavailable. */
final class CalendarWarningFallbackTest extends CalendarTestCase {
	/** @return iterable<string, array{bool,string}> Civil-day and timed collisions in neutral request languages. */
	public static function collisions(): iterable {
		foreach ([false, true] as $allDay) {
			foreach (['en', 'de', 'pt_BR'] as $locale) {
				yield (int)$allDay . '-' . $locale => [$allDay, $locale];
			}
		}
	}

	/**
	 * @param bool $allDay Whether the overlap is a civil day or a timed appointment.
	 * @param string $locale Request language.
	 * @return void The client receives the plan and overlap warning, with no DAV write.
	 */
	#[DataProvider('collisions')]
	public function testCollisionDoesNotRequireIntl(bool $allDay, string $locale): void {
		\OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n($locale));
		$timing = $allDay ? "DTSTART;VALUE=DATE:20261201\nDTEND;VALUE=DATE:20261203"
			: "DTSTART:20261201T090000Z\nDTEND:20261201T100000Z";
		$this->store->addObject(1, 'overlap.ics', self::ics("UID:overlap\nSUMMARY:Neutral overlap\n" . $timing));
		$args = ['calendar' => self::PERSONAL, 'summary' => 'Neutral proposal'] + ($allDay
			? ['start' => '2026-12-01', 'end' => '2026-12-03', 'allDay' => true]
			: ['start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T10:00:00Z', 'timeZone' => 'Europe/Berlin']);
		$this->store->addObject(1, 'edit.ics', self::ics("UID:edit\nSUMMARY:Neutral existing\nDTSTART:20261101T090000Z\nDTEND:20261101T100000Z"));
		foreach (['calendar_create_event', 'calendar_update_event'] as $tool) {
			$result = $this->registry->call($tool, $args + ($tool === 'calendar_update_event' ? ['uid' => 'edit'] : []), 'alice');
			self::assertArrayNotHasKey('isError', $result, $result['content'][0]['text'] ?? '');
			$plan = self::json($result);
			self::assertTrue($plan['requiresConfirmation']);
			self::assertSame('collision', $plan['warnings'][0]['type']);
			self::assertStringContainsString('Neutral overlap', $plan['warnings'][0]['message']);
			if (!class_exists(\IntlDateFormatter::class)) {
				self::assertStringContainsString($allDay ? '2026-12-01 – 2026-12-02' : '2026-12-01, 10:00–11:00', $plan['warnings'][0]['message']);
			}
		}
		$this->assertNoWrites();
	}
}
