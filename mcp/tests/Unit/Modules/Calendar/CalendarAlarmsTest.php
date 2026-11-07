<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;
use OCA\Mcp\Modules\Calendar\Locating\StoredEvent;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Reader;

/** Reminder changes through the existing write/preview pipeline and recorded DAV effects. */
final class CalendarAlarmsTest extends CalendarTestCase {
	/** @return array<string, mixed> minimal all-day create */
	private function createArgs(): array {
		return ['calendar' => self::PERSONAL, 'summary' => 'Meeting', 'start' => '2026-12-01', 'end' => '2026-12-02', 'allDay' => true];
	}

	/** @return iterable<string, array{int}> supported list sizes */
	public static function sizes(): iterable {
		foreach ([0, 1, 5] as $size) {
			yield (string)$size => [$size];
		}
	}

	/**
	 * @param int $size number of duplicates, which must not be deduplicated
	 * @return void
	 */
	#[DataProvider('sizes')]
	public function testCreateAndUpdateWriteExactListsWithReadback(int $size): void {
		$alarms = array_fill(0, $size, ['minutesBefore' => 15]);
		$created = self::json($this->registry->call('calendar_create_event', $this->createArgs() + ['alarms' => $alarms, 'confirm' => true], 'alice'));
		self::assertSame('applied', $created['alarmReceipt']['status']);
		self::assertCount($size, $created['alarmReceipt']['observed']);
		self::assertFalse($created['alarmReceipt']['notificationDeliveryVerified']);
		self::assertArrayNotHasKey('overridesMessage', $created['alarmReceipt']);
		foreach ($created['alarmReceipt']['observed'] as $row) {
			self::assertSame([-900, 'Meeting'], [$row['offsetSeconds'], $row['description']]);
		}
		$updated = self::json($this->registry->call('calendar_update_event', [
			'calendar' => self::PERSONAL, 'uid' => $created['uid'], 'alarms' => $alarms, 'summary' => 'Changed', 'confirm' => true,
		], 'alice'));
		self::assertSame('applied', $updated['alarmReceipt']['status']);
		self::assertCount($size, $updated['alarmReceipt']['observed']);
		foreach ($updated['alarmReceipt']['observed'] as $row) {
			self::assertSame('Changed', $row['description']);
		}
		self::assertCount(2, $this->dav->calls);
		self::assertNotEmpty($this->dav->calls[1][1][2], 'If-Match remains mandatory');
	}

	/** @return void omission leaves the old public envelopes and reminders alone */
	public function testOmissionKeepsLegacyBehavior(): void {
		$created = self::json($this->call('calendar_create_event', $this->createArgs()));
		self::assertArrayNotHasKey('alarmReceipt', $created);
		$this->seed();
		$before = $this->stored();
		$updated = self::json($this->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'mixed', 'summary' => 'Changed']));
		self::assertArrayNotHasKey('alarmReceipt', $updated);
		self::assertSame(EventAlarms::fingerprints($before, true), EventAlarms::fingerprints($this->stored(), true));
		self::assertSame(EventAlarms::fingerprints($before, false), EventAlarms::fingerprints($this->stored(), false));
		$plan = $this->module->preview('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'mixed', 'summary' => 'Again'], 'alice');
		self::assertArrayNotHasKey('alarmChange', $plan);
	}

	/** @return void opt-in reminders use the same legacy result mapping even with an override first */
	public function testUpdateMappingMatchesLegacyWhenMasterIsNotFirst(): void {
		$override = self::ics("UID:series\nSUMMARY:Occurrence\nRECURRENCE-ID:20261202T120000Z\nDTSTART:20261202T140000Z\nDTEND:20261202T150000Z",
			"BEGIN:VEVENT\nUID:series\nSUMMARY:Master\nDTSTART:20261201T120000Z\nDTEND:20261201T130000Z\nRRULE:FREQ=DAILY\nEND:VEVENT");
		$results = [];
		foreach ([[], ['alarms' => []]] as $extra) {
			$this->store->addObject(1, 'series.ics', $override);
			$result = self::json($this->call('calendar_update_event', [
				'calendar' => self::PERSONAL, 'uid' => 'series', 'summary' => 'Master changed',
			] + $extra));
			self::assertSame('Occurrence', $result['summary']);
			unset($result['etag'], $result['alarmReceipt']);
			$results[] = $result;
		}
		self::assertSame($results[0], $results[1]);
	}

	/** @return iterable<string, array{string, string}> required presentation languages */
	public static function locales(): iterable {
		yield 'English' => ['en', 'DISPLAY reminders relative'];
		yield 'Portuguese' => ['pt_BR', 'Lembretes DISPLAY relativos'];
		yield 'Spanish' => ['es', 'Recordatorios DISPLAY relativos'];
		yield 'German' => ['de', 'DISPLAY-Erinnerungen relativ'];
	}

	/**
	 * @param string $locale request language
	 * @param string $fragment localized reminder section
	 * @return void plan and receipt share the request translator
	 */
	#[DataProvider('locales')]
	public function testLocalizedReminderPlansAndReceipts(string $locale, string $fragment): void {
		Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n($locale));
		$args = $this->createArgs() + ['alarms' => [['minutesBefore' => 15, 'description' => '**literal**']]];
		$plan = $this->module->preview('calendar_create_event', $args, 'alice');
		$text = \OCA\Mcp\Mcp\Confirmation\PlanRenderer::render($this->module, 'calendar_create_event', $plan);
		$reminders = implode("\n", \OCA\Mcp\Modules\Calendar\Presentation\AlarmPlan::lines($plan));
		self::assertStringContainsString($fragment, $reminders);
		self::assertStringContainsString('\*\*literal\*\*', $reminders);
		self::assertStringContainsString($plan['warnings'][0]['message'], $text);
		$this->assertNoWrites();
		$result = self::json($this->registry->call('calendar_create_event', $args + ['confirm' => true], 'alice'));
		$message = $result['alarmReceipt']['message'];
		self::assertSame($locale === 'en', str_starts_with($message, 'Reminders were'));
		self::assertSame('**literal**', $result['alarmReceipt']['observed'][0]['description']);
	}

	/** @return void clear removes only eligible master components and leaves overrides untouched */
	public function testSelectiveClearAndSharedPreviewExplainPreservation(): void {
		Translator::reset();
		$this->seed();
		$before = $this->stored();
		$args = ['calendar' => self::PERSONAL, 'uid' => 'mixed', 'alarms' => []];
		$plan = $this->module->preview('calendar_update_event', $args, 'alice');
		self::assertCount(3, $plan['alarmChange']['before']);
		self::assertCount(6, $plan['alarmChange']['preserved']);
		self::assertSame(1, $plan['alarmChange']['overrideCount']);
		$text = \OCA\Mcp\Mcp\Confirmation\PlanRenderer::render($this->module, 'calendar_update_event', $plan);
		self::assertStringContainsString('+1 more reminders', $text);
		self::assertStringContainsString('does not receive these reminders', $text);
		self::assertStringContainsString('2026-12-01', $text);
		$this->assertNoWrites();
		$result = self::json($this->registry->call('calendar_update_event', $args + ['confirm' => true], 'alice'));
		self::assertSame('applied', $result['alarmReceipt']['status']);
		self::assertSame([], EventAlarms::inspect($this->stored())['managed']);
		self::assertSame(EventAlarms::fingerprints($before, false), EventAlarms::fingerprints($this->stored(), false));
		self::assertSame(EventAlarms::overrides($before), EventAlarms::overrides($this->stored()));
		$shared = $this->createArgs();
		$shared['calendar'] = self::TEAM;
		$shared['alarms'] = [['minutesBefore' => 0]];
		$plan = $this->module->preview('calendar_create_event', $shared, 'alice');
		self::assertStringContainsString('shared event', \OCA\Mcp\Mcp\Confirmation\PlanRenderer::render($this->module, 'calendar_create_event', $plan));
		$refused = $this->registry->call('calendar_create_event', $shared + ['confirm' => true], 'alice');
		self::assertTrue($refused['isError']);
		self::assertCount(1, $this->dav->calls, 'shared refusal dispatches nothing');
		self::json($this->registry->call('calendar_create_event', $shared + ['confirm' => true, 'confirm_shared' => true], 'alice'));
		self::assertCount(2, $this->dav->calls);
	}

	/** @return void save every non-eligible alarm and a separate recurrence override */
	private function seed(): void {
		$parts = [
			'ACTION:DISPLAY\nTRIGGER:-PT15M\nDESCRIPTION:Old',
			'ACTION:DISPLAY\nTRIGGER;RELATED=START:PT2S\nDESCRIPTION:After',
			'ACTION:DISPLAY\nTRIGGER:-P5W\nREPEAT:2\nDURATION:PT2M\nDESCRIPTION:Repeated',
			'ACTION:EMAIL\nTRIGGER:-PT15M\nATTENDEE:mailto:outside@example.invalid\nDESCRIPTION:Email',
			'ACTION:DISPLAY\nTRIGGER;VALUE=DATE-TIME:20261201T120000Z\nDESCRIPTION:Absolute',
			'ACTION:AUDIO\nTRIGGER:-PT5M\nATTACH:https://example.invalid/sound',
			'ACTION:DISPLAY\nTRIGGER;RELATED=END:-PT10M\nDESCRIPTION:End',
			'ACTION:DISPLAY\nTRIGGER:invalid\nDESCRIPTION:Malformed',
			'ACTION:X-OTHER\nTRIGGER:-PT2M\nX-CUSTOM:keep',
		];
		$body = "UID:mixed\nSUMMARY:Meeting\nDTSTART:20261201T120000Z\nDTEND:20261201T130000Z\nRRULE:FREQ=DAILY\n";
		foreach ($parts as $part) {
			$body .= "BEGIN:VALARM\n" . str_replace('\n', "\n", $part) . "\nEND:VALARM\n";
		}
		$override = "BEGIN:VEVENT\nUID:mixed\nRECURRENCE-ID:20261202T120000Z\nDTSTART:20261202T140000Z\nDTEND:20261202T150000Z\nBEGIN:VALARM\nACTION:AUDIO\nTRIGGER:-PT2M\nEND:VALARM\nEND:VEVENT";
		$this->store->addObject(1, 'mixed.ics', self::ics($body, $override));
	}

	/** @return \Sabre\VObject\Component\VCalendar current server-side fake object */
	private function stored(): \Sabre\VObject\Component\VCalendar {
		return Reader::read($this->store->objects[1]['mixed.ics']['data']);
	}
}
