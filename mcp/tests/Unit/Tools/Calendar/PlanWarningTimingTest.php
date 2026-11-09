<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarTimingFormatter;
use OCA\Mcp\Modules\Calendar\Scheduling\PlanWarnings;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Overlap timing uses the plan renderer's single-instant display when an interval is zero or unusable. */
final class PlanWarningTimingTest extends TestCase {
	/** @return iterable<string, array{string,bool,string}> Zero duration and rejected ends, with timed and civil starts. */
	public static function intervals(): iterable {
		foreach (['en', 'de', 'pt_BR'] as $locale) {
			foreach ([false, true] as $allDay) {
				foreach (['zero' => '2026-12-01T09:00:00Z', 'reversed' => '2026-11-30T09:00:00Z', 'invalid-end' => 'not-a-date'] as $case => $end) {
					yield $locale . '-' . (int)$allDay . '-' . $case => [$end, $allDay, $locale];
				}
			}
		}
	}

	/** @return void Request language never leaks into the next test. */
	protected function tearDown(): void {
		Translator::reset();
		parent::tearDown();
	}

	/**
	 * @param string $end Equal, reversed or unparseable interval end from diagnostic input.
	 * @param bool $allDay Whether the warning names a civil date or a local date and time.
	 * @param string $locale Request language.
	 * @return void A valid ISO start from CollisionCheck never becomes an empty warning time.
	 */
	#[DataProvider('intervals')]
	public function testWarningKeepsTheFormattedStart(string $end, bool $allDay, string $locale): void {
		Translator::use(new JsonL10n($locale));
		$start = '2026-12-01T09:00:00Z';
		$zone = new DateTimeZone('Europe/Berlin');
		$expected = (new CalendarTimingFormatter())->format($start, $start, $allDay, $zone->getName(), $zone->getName(), $locale);
		self::assertNotEmpty($expected);
		// Isolate defensive formatting: invalid ends cannot be manufactured through the validated write arguments.
		$warnings = (new ReflectionClass(PlanWarnings::class))->newInstanceWithoutConstructor();
		$actual = (new ReflectionMethod(PlanWarnings::class, 'when'))->invoke($warnings, $start, $end, $allDay, $zone);
		self::assertSame($expected, $actual);
		self::assertStringNotContainsString('–', $actual, 'A single instant is shown once, like the plan renderer');
	}
}
