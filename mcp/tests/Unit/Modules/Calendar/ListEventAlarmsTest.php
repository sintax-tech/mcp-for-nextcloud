<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Presentation\EventAlarmListing;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises reminder projections through the published list tool and its visibility gate. */
final class ListEventAlarmsTest extends CalendarTestCase {
	/** @return iterable<string, array{string, string}> source and translated guidance */
	public static function languages(): iterable {
		yield 'en' => ['en', 'includes alarms'];
		yield 'pt_BR' => ['pt_BR', 'inclui alarms'];
		yield 'de' => ['de', 'enthält alarms'];
		yield 'es' => ['es', 'incluye alarms'];
	}

	/**
	 * @param string $language account language
	 * @param string $fragment expected guidance translation
	 * @return void reminder guidance is available in every supported language
	 */
	#[DataProvider('languages')]
	public function testLocalizedGuidance(string $language, string $fragment): void {
		Translator::use(new JsonL10n($language));
		self::assertStringContainsString($fragment, EventAlarmListing::note());
		self::assertContains(EventAlarmListing::note(), $this->module->guideNotes());
	}

	/** @return iterable<string, array{int}> supported reminder counts */
	public static function counts(): iterable {
		foreach ([0, 1, 2] as $count) {
			yield (string)$count => [$count];
		}
	}

	/**
	 * @param int $count reminders on the stored event
	 * @return void the list exposes precisely their offset and description
	 */
	#[DataProvider('counts')]
	public function testListsZeroOneAndTwoReminders(int $count): void {
		$alarms = '';
		$expected = [];
		for ($i = 1; $i <= $count; $i++) {
			$alarms .= self::alarm('DISPLAY', '-PT' . $i . 'M', 'Reminder ' . $i);
			$expected[] = ['minutesBefore' => $i, 'description' => 'Reminder ' . $i];
		}
		$this->addEvent(1, $alarms);
		$item = $this->items(self::PERSONAL)[0];
		self::assertSame($expected, $item['alarms']);
		self::assertSame(0, $item['otherAlarms']);
	}

	/** @return void unhandled components are counted without leaking their description or action */
	public function testOtherAlarmsAreOnlyCounted(): void {
		$this->addEvent(1, self::alarm('DISPLAY', '-PT5M', 'Visible')
			. self::alarm('AUDIO', '-PT10M', 'Secret audio')
			. self::alarm('DISPLAY', '-PT2M', 'Secret end', ';RELATED=END')
			. self::alarm('DISPLAY', '20260311T090000Z', 'Secret absolute', ';VALUE=DATE-TIME'));
		$item = $this->items(self::PERSONAL)[0];
		self::assertSame([['minutesBefore' => 5, 'description' => 'Visible']], $item['alarms']);
		self::assertSame(3, $item['otherAlarms']);
		self::assertStringNotContainsString('Secret', json_encode($item, JSON_THROW_ON_ERROR));
	}

	/** @return void confidential events reveal neither reminder content nor counts */
	public function testConfidentialAlarmsAreNotExposed(): void {
		$this->addEvent(3, self::alarm('DISPLAY', '-PT5M', 'Secret'), 'CLASS:CONFIDENTIAL');
		$item = $this->items(self::TEAM)[0];
		self::assertArrayNotHasKey('alarms', $item);
		self::assertArrayNotHasKey('otherAlarms', $item);
		self::assertStringNotContainsString('Secret', json_encode($item, JSON_THROW_ON_ERROR));
	}

	/** @return void native read-only shares hide all reminder fields even on public events */
	public function testReadOnlyShareHidesOwnerReminders(): void {
		$this->addEvent(4, self::alarm('DISPLAY', '-PT5M', 'OwnerSecret')
			. self::alarm('AUDIO', '-PT10M', 'OwnerAudio'), 'CLASS:PUBLIC');
		$item = $this->items(self::READONLY)[0];
		self::assertArrayNotHasKey('alarms', $item);
		self::assertArrayNotHasKey('otherAlarms', $item);
		self::assertStringNotContainsString('OwnerSecret', json_encode($item, JSON_THROW_ON_ERROR));
	}

	/** @return void a writable share still exposes its public event reminders */
	public function testWritableShareShowsReminders(): void {
		$this->addEvent(3, self::alarm('DISPLAY', '-PT5M', 'Team reminder'), 'CLASS:PUBLIC');
		$item = $this->items(self::TEAM)[0];
		self::assertSame([['minutesBefore' => 5, 'description' => 'Team reminder']], $item['alarms']);
		self::assertSame(0, $item['otherAlarms']);
	}

	/** @return void recurrence overrides keep their own reminder projection */
	public function testOccurrenceUsesItsOwnAlarms(): void {
		$master = "UID:r\nDTSTART:20260310T100000Z\nDTEND:20260310T110000Z\nRRULE:FREQ=DAILY;COUNT=2\n"
			. self::alarm('DISPLAY', '-PT5M', 'Master');
		$override = "BEGIN:VEVENT\nUID:r\nRECURRENCE-ID:20260311T100000Z\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z\n"
			. self::alarm('DISPLAY', '-PT1M', 'Override') . "END:VEVENT";
		$this->store->addObject(1, 'r.ics', self::ics($master, $override));
		self::assertSame([
			[['minutesBefore' => 5, 'description' => 'Master']],
			[['minutesBefore' => 1, 'description' => 'Override']],
		], array_column($this->items(self::PERSONAL), 'alarms'));
	}

	/**
	 * @param int $calendar fixture calendar ID
	 * @param string $alarms components to store
	 * @param string $classification optional CLASS property
	 * @return void
	 */
	private function addEvent(int $calendar, string $alarms, string $classification = ''): void {
		$this->store->addObject($calendar, 'a.ics', self::ics("UID:a\nDTSTART:20260311T100000Z\nDTEND:20260311T110000Z\n"
			. $classification . "\n" . $alarms));
	}

	/**
	 * @param string $calendar fixture calendar path
	 * @return list<array<string, mixed>> decoded list output
	 */
	private function items(string $calendar): array {
		return self::json($this->call('calendar_list_events', ['calendar' => $calendar, 'from' => '2026-03-10', 'to' => '2026-03-17']));
	}

	/**
	 * @param string $action alarm action
	 * @param string $trigger trigger value
	 * @param string $description stored text
	 * @param string $parameters optional trigger parameters
	 * @return string VALARM component
	 */
	private static function alarm(string $action, string $trigger, string $description, string $parameters = ''): string {
		return "BEGIN:VALARM\nACTION:$action\nTRIGGER$parameters:$trigger\nDESCRIPTION:$description\nEND:VALARM\n";
	}
}
