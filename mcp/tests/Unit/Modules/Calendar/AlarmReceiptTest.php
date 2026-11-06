<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Writing\AlarmReceipt;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Component\VCalendar;

/** Fresh server readback, including an applied create whose reminders differ or cannot be observed. */
final class AlarmReceiptTest extends CalendarTestCase {
	/** @return iterable<string, array{string, string}> observation failure modes */
	public static function observations(): iterable {
		yield 'equivalent duration' => ['equivalent', 'applied'];
		yield 'different reminders' => ['changed', 'partial'];
		yield 'preserved reminder changed' => ['preserved', 'partial'];
		yield 'missing readback' => ['missing', 'uncertain'];
		yield 'unreadable readback' => ['invalid', 'uncertain'];
		yield 'readback throws' => ['throws', 'uncertain'];
		yield 'write times out' => ['timeout', 'uncertain'];
		yield 'override guest changed' => ['guest', 'applied'];
		yield 'override reminder changed' => ['override', 'partial'];
		yield 'server error' => ['server', 'uncertain'];
	}

	/**
	 * @param string $mode injected server behavior
	 * @param string $status expected receipt
	 * @return void no result infers delivery or issues a second write
	 */
	#[DataProvider('observations')]
	public function testReadbackDeterminesReceiptAndNoRetry(string $mode, string $status): void {
		Translator::reset();
		$proposed = new VCalendar();
		$event = $proposed->add('VEVENT', ['UID' => 'known-uid', 'SUMMARY' => 'x']);
		$event->add('VALARM', ['ACTION' => 'AUDIO', 'TRIGGER' => '-PT1M']);
		$override = $proposed->add('VEVENT', ['UID' => 'known-uid', 'RECURRENCE-ID' => '20261202T120000Z']);
		$override->add('ATTENDEE', 'mailto:guest@example.invalid');
		$override->add('VALARM', ['ACTION' => 'DISPLAY', 'TRIGGER' => '-PT2M', 'DESCRIPTION' => 'Own reminder']);
		$before = clone $proposed;
		EventAlarms::apply($event, ['alarms' => [['minutesBefore' => 60]]]);
		$written = clone $proposed;
		if ($mode === 'equivalent') {
			$written->VEVENT->select('VALARM')[1]->TRIGGER = '-PT1H';
		} elseif ($mode === 'changed') {
			$written->VEVENT->select('VALARM')[1]->DESCRIPTION = 'Server changed text';
		} elseif ($mode === 'preserved') {
			$written->VEVENT->select('VALARM')[0]->TRIGGER = '-PT2M';
		}
		if ($mode === 'guest') {
			$written->select('VEVENT')[1]->ATTENDEE['SCHEDULE-STATUS'] = '1.2';
		} elseif ($mode === 'override') {
			$written->select('VEVENT')[1]->VALARM->DESCRIPTION = 'Changed override reminder';
		}
		$writes = 0;
		$result = AlarmReceipt::dispatch($proposed, $before, new EventRepository(new FakeCalendarStore(), new Classification()),
			function () use (&$writes, $mode): void {
				$writes++;
				if ($mode === 'timeout') {
					throw new \RuntimeException('timeout after possible write');
				}
				if ($mode === 'server') {
					throw new CalendarException('server failure', 503);
				}
			},
			function () use ($written, $mode): ?array {
				if ($mode === 'throws') {
					throw new \RuntimeException('read failed');
				}
				return $mode === 'missing' ? null : ['etag' => 'fresh', 'data' => $mode === 'invalid' ? 'invalid' : $written->serialize()];
			},
			static fn ($calendar, $row): array => ['uid' => 'known-uid', 'etag' => $row['etag']],
		);
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(1, $writes);
		self::assertSame('known-uid', $data['uid']);
		self::assertSame($status, $data['alarmReceipt']['status']);
		self::assertSame($status === 'uncertain', $result['isError'] ?? false);
		self::assertFalse($data['alarmReceipt']['notificationDeliveryVerified']);
		if ($status === 'uncertain') {
			self::assertStringContainsString('Do not repeat the change', $data['alarmReceipt']['message']);
			self::assertArrayNotHasKey('observed', $data['alarmReceipt']);
		} else {
			self::assertSame('fresh', $data['etag']);
		}
	}

	/** @return void failed create readback includes generated identity through the actual draft */
	public function testCreateMissingReadbackHasUidAndNoRetry(): void {
		$this->dav->statusFor['put'] = 201;
		$result = $this->registry->call('calendar_create_event', [
			'calendar' => self::PERSONAL, 'summary' => 'x', 'start' => '2026-12-01', 'end' => '2026-12-02', 'allDay' => true,
			'alarms' => [['minutesBefore' => 15]], 'confirm' => true,
		], 'alice');
		self::assertTrue($result['isError']);
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($data['uid'] . '.ics', $this->dav->calls[0][1][1]);
		self::assertSame('uncertain', $data['alarmReceipt']['status']);
		self::assertCount(1, $this->dav->calls);
	}
}
