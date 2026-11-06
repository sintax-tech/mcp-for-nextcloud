<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Presentation\AlarmSchema;
use OCA\Mcp\Modules\Calendar\Tools\CreateEvent;
use OCA\Mcp\Modules\Calendar\Tools\UpdateEvent;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** Closed input boundaries, direct draft refusals and text roundtrips. */
final class AlarmInputTest extends CalendarTestCase {
	/** @return iterable<string, array{mixed}> malformed reminder inputs */
	public static function invalid(): iterable {
		yield 'too many' => [array_fill(0, 6, ['minutesBefore' => 1])];
		foreach ([-1, 40321, '15', 1.5, false, null] as $i => $minutes) {
			yield 'minutes ' . $i => [[['minutesBefore' => $minutes]]];
		}
		yield 'null list' => [null];
		yield 'object list' => [['minutesBefore' => 1]];
		yield 'scalar item' => [[1]];
		yield 'missing minutes' => [[['description' => 'x']]];
		yield 'extra field' => [[['minutesBefore' => 1, 'action' => 'EMAIL']]];
		yield 'null description' => [[['minutesBefore' => 1, 'description' => null]]];
		yield 'too long' => [[['minutesBefore' => 1, 'description' => str_repeat('é', 65537)]]];
	}

	/**
	 * @param mixed $alarms malformed list
	 * @return void both published contracts and a direct draft refuse before any effect
	 */
	#[DataProvider('invalid')]
	public function testInvalidInputsNeverWrite(mixed $alarms): void {
		foreach ([CreateEvent::class, UpdateEvent::class] as $tool) {
			$args = ['calendar' => self::PERSONAL, 'uid' => 'e', 'summary' => 'x', 'start' => '2026-12-01', 'end' => '2026-12-02', 'alarms' => $alarms];
			if ($tool === CreateEvent::class) {
				unset($args['uid']);
			}
			try {
				ArgumentValidator::validate($tool::definition()->inputSchema, $args);
				self::fail('expected schema refusal');
			} catch (ArgumentValidationException) {
				$this->assertNoWrites();
			}
		}
		try {
			$this->call('calendar_create_event', [
				'calendar' => self::PERSONAL, 'summary' => 'x', 'start' => '2026-12-01', 'end' => '2026-12-02', 'allDay' => true, 'alarms' => $alarms,
			]);
			self::fail('expected draft refusal');
		} catch (CalendarArgumentException) {
			$this->assertNoWrites();
		}
	}

	/** @return void boundary values are inclusive; text remains text after folding/escaping */
	public function testBoundsFallbacksAndTextRoundtrip(): void {
		$calendar = new VCalendar();
		$event = $calendar->add('VEVENT', ['UID' => 'e', 'SUMMARY' => 'Final title']);
		$text = "é,;\\\nBEGIN:VALARM\nACTION:EMAIL";
		EventAlarms::apply($event, ['alarms' => [
			['minutesBefore' => 0, 'description' => ''],
			['minutesBefore' => 40320, 'description' => $text],
			['minutesBefore' => 1, 'description' => str_repeat('é', 65536)],
		]]);
		$parsed = Reader::read($calendar->serialize());
		self::assertCount(3, $parsed->VEVENT->select('VALARM'));
		self::assertSame('Final title', (string)$parsed->VEVENT->select('VALARM')[0]->DESCRIPTION);
		self::assertSame('PT0M', (string)$parsed->VEVENT->select('VALARM')[0]->TRIGGER);
		self::assertSame($text, (string)$parsed->VEVENT->select('VALARM')[1]->DESCRIPTION);
		self::assertSame(-40320 * 60, EventAlarms::offset($parsed->VEVENT->select('VALARM')[1]));
		self::assertSame(65536, mb_strlen((string)$parsed->VEVENT->select('VALARM')[2]->DESCRIPTION));
		unset($event->SUMMARY);
		\OCA\Mcp\L10n\Translator::reset();
		EventAlarms::apply($event, ['alarms' => [['minutesBefore' => 0]]]);
		self::assertSame('Event reminder', (string)$event->VALARM->DESCRIPTION);
		self::assertArrayNotHasKey('default', AlarmSchema::property());
	}
}
