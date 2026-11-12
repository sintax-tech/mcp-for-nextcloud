<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityCandidates;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityWindow;
use OCA\Mcp\Modules\Calendar\Reading\SuggestionInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact wall-clock grid, real elapsed durations, DST segments and closed input boundaries. */
final class AvailabilityCandidatesTest extends TestCase {
	/** @return array<string,mixed> explicit seven-day all-hours baseline */
	public static function args(): array {
		return ['participants' => ['bob'], 'from' => '2026-11-02T00:00:00Z', 'to' => '2026-11-09T00:00:00Z', 'timezone' => 'UTC',
			'duration_minutes' => 15, 'step_minutes' => 15, 'working_hours' => ['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '24:00']];
	}

	/** @param array<string,mixed> $arguments search @return array exact candidate instants */
	private function candidates(array $arguments): array {
		return (new AvailabilityCandidates())->build((new SuggestionInput(new AvailabilityWindow()))->parse($arguments));
	}

	/** @return void seven days remain bounded and both duration endpoints are inclusive */
	public function testSevenDayGridAndMaximumDuration(): void {
		$all = $this->candidates(self::args());
		self::assertCount(672, $all);
		self::assertSame('2026-11-02T00:00:00+00:00', $all[0]['start']->format(DATE_ATOM));
		self::assertSame('2026-11-09T00:00:00+00:00', $all[671]['end']->format(DATE_ATOM));
		$long = $this->candidates(array_replace(self::args(), ['duration_minutes' => 240, 'step_minutes' => 30,
			'working_hours' => ['weekdays' => [1], 'start' => '09:00', 'end' => '17:00']]));
		self::assertCount(9, $long);
		self::assertSame('13:00', $long[8]['start']->format('H:i'));
		foreach ($long as $window) {
			self::assertSame(1, (int)$window['start']->format('N'));
			self::assertSame(14400, $window['end']->getTimestamp() - $window['start']->getTimestamp());
		}
	}

	/** @return void a local seven-day horizon can contain 167 or 169 real hours without becoming an eighth local day */
	public function testSevenLocalDaysAcrossDstUseTheCorrectRealHorizon(): void {
		foreach ([['2026-03-02T00:00:00-05:00', '2026-03-09T00:00:00-04:00', 668],
			['2026-10-26T00:00:00-04:00', '2026-11-02T00:00:00-05:00', 676]] as [$from, $to, $count]) {
			$windows = $this->candidates(array_replace(self::args(), ['from' => $from, 'to' => $to, 'timezone' => 'America/New_York']));
			self::assertCount($count, $windows);
			self::assertSame($to, $windows[$count - 1]['end']->format(DATE_ATOM));
		}
	}

	/** @return void caller's work start anchors the grid; horizon clipping never rounds a timestamp into scope */
	public function testWorkStartAnchorsTheGridAndHorizonClipsCandidates(): void {
		$windows = $this->candidates(array_replace(self::args(), ['from' => '2026-11-02T09:10:01Z', 'to' => '2026-11-02T11:00:00Z',
			'step_minutes' => 30, 'working_hours' => ['weekdays' => [1], 'start' => '09:10', 'end' => '10:55']]));
		self::assertSame(['09:40', '10:10', '10:40'], array_map(static fn ($w) => $w['start']->format('H:i'), $windows));
		self::assertSame('10:55', $windows[2]['end']->format('H:i'));
	}

	/** @return iterable<string,array{array<string,mixed>,list<string>}> DST fixtures contain exact offsets, never server-specific timezone defaults */
	public static function dst(): iterable {
		yield 'spring gap' => [[
			'from' => '2026-03-08T00:00:00-05:00', 'to' => '2026-03-09T00:00:00-04:00', 'timezone' => 'America/New_York',
			'working_hours' => ['weekdays' => [7], 'start' => '01:00', 'end' => '04:00'],
		], ['01:00-05:00', '01:30-05:00', '03:00-04:00', '03:30-04:00']];
		yield 'fall fold' => [[
			'from' => '2026-11-01T00:00:00-04:00', 'to' => '2026-11-02T00:00:00-05:00', 'timezone' => 'America/New_York',
			'working_hours' => ['weekdays' => [7], 'start' => '01:00', 'end' => '03:00'],
		], ['01:00-04:00', '01:30-04:00', '01:00-05:00', '01:30-05:00', '02:00-05:00', '02:30-05:00']];
		yield 'half-hour fold' => [[
			'from' => '2026-04-05T00:00:00+11:00', 'to' => '2026-04-06T00:00:00+10:30', 'timezone' => 'Australia/Lord_Howe',
			'working_hours' => ['weekdays' => [7], 'start' => '01:00', 'end' => '03:00'],
		], ['01:00+11:00', '01:30+11:00', '01:30+10:30', '02:00+10:30', '02:30+10:30']];
	}

	/** @param array<string,mixed> $patch local interval @param list<string> $expected real chronological starts @return void gaps vanish, folds retain both instants */
	#[DataProvider('dst')]
	public function testDstGridPreservesRealDurations(array $patch, array $expected): void {
		$windows = $this->candidates(array_replace(self::args(), ['duration_minutes' => 30, 'step_minutes' => 30], $patch));
		self::assertSame($expected, array_map(static fn ($w) => $w['start']->format('H:iP'), $windows));
		$previous = null;
		foreach ($windows as $window) {
			self::assertSame(1800, $window['end']->getTimestamp() - $window['start']->getTimestamp());
			if ($previous !== null) self::assertGreaterThan($previous, $window['start']->getTimestamp());
			$previous = $window['start']->getTimestamp();
		}
	}

	/** @return void matching wall-clock endpoints alone must not bridge a forbidden part of a fold */
	public function testFoldCannotEscapeTheUsersWorkingHoursInItsMiddle(): void {
		$windows = $this->candidates(array_replace(self::args(), ['from' => '2026-11-01T00:00:00-04:00', 'to' => '2026-11-02T00:00:00-05:00',
			'timezone' => 'America/New_York', 'duration_minutes' => 60,
			'working_hours' => ['weekdays' => [7], 'start' => '01:00', 'end' => '01:30']]));
		self::assertSame([], $windows);
	}

	/** @return void a skipped calendar date does not normalize into duplicate next-day candidates */
	public function testSkippedDateDoesNotDuplicateCandidates(): void {
		$windows = $this->candidates(array_replace(self::args(), ['from' => '2011-12-29T00:00:00-10:00', 'to' => '2012-01-02T00:00:00+14:00',
			'timezone' => 'Pacific/Apia', 'duration_minutes' => 60, 'step_minutes' => 30,
			'working_hours' => ['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start' => '09:00', 'end' => '10:00']]));
		self::assertSame(['2011-12-29', '2011-12-31', '2012-01-01'], array_map(static fn ($w) => $w['start']->format('Y-m-d'), $windows));
	}

	/** @return iterable<string,array{array<string,mixed>}> malformed schedule and closed budgets */
	public static function invalid(): iterable {
		foreach ([14, 241, '30', null] as $i => $duration) yield 'duration ' . $i => [['duration_minutes' => $duration]];
		foreach ([0, 10, '15', null] as $i => $step) yield 'step ' . $i => [['step_minutes' => $step]];
		yield 'horizon too long' => [['to' => '2026-11-09T00:00:01Z']];
		yield 'empty horizon' => [['to' => '2026-11-02T00:00:00Z']];
		yield 'reverse horizon' => [['to' => '2026-11-01T00:00:00Z']];
		yield 'implicit instant' => [['from' => '2026-11-02T00:00:00']];
		yield 'bad date' => [['from' => '2026-02-30T00:00:00Z']];
		yield 'bad zone' => [['timezone' => 'Nowhere/Unknown']];
		foreach ([[], [0], [8], [1, 1], ['1']] as $i => $days) yield 'weekdays ' . $i => [['working_hours' => ['weekdays' => $days, 'start' => '09:00', 'end' => '17:00']]];
		foreach ([['17:00', '09:00'], ['09:00', '09:00'], ['24:00', '24:00'], ['9:00', '17:00']] as $i => [$start, $end]) {
			yield 'hours ' . $i => [['working_hours' => ['weekdays' => [1], 'start' => $start, 'end' => $end]]];
		}
		foreach ([-1, 1025, '1', 0.5] as $i => $index) yield 'index ' . $i => [['next_index' => $index]];
	}

	/** @param array<string,mixed> $patch invalid field @return void domain validation is not reliant on a schema-only minimum */
	#[DataProvider('invalid')]
	public function testInvalidScheduleIsRejected(array $patch): void {
		$this->expectException(CalendarArgumentException::class);
		$this->candidates(array_replace(self::args(), $patch));
	}
}
