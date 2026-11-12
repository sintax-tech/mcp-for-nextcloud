<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;

/** Explicit caller work hours and bounded horizon; never infers an account's working schedule. */
final class SuggestionInput {
	/** @param AvailabilityWindow $dates the exact date/time parser also used by FB-1 */
	public function __construct(private AvailabilityWindow $dates) {}

	/**
	 * @param array<string, mixed> $arguments raw validated tool arguments
	 * @return array{from:\DateTimeImmutable,to:\DateTimeImmutable,zone:\DateTimeZone,duration:int,step:int,weekdays:list<int>,workStart:int,workEnd:int,index:int} normalized bounded search
	 * @throws CalendarArgumentException on malformed or over-budget inputs
	 */
	public function parse(array $arguments): array {
		$zone = $this->dates->timezone($arguments['timezone'] ?? null);
		$from = $this->dates->instant($arguments['from'] ?? null)->setTimezone($zone);
		$to = $this->dates->instant($arguments['to'] ?? null)->setTimezone($zone);
		if ($to <= $from || $to > $from->modify('+7 days') || $to->getTimestamp() - $from->getTimestamp() > 8 * 86400) {
			throw new CalendarArgumentException(Translator::t('The suggestion horizon must be positive and at most seven local days.'));
		}
		$duration = $arguments['duration_minutes'] ?? null;
		$step = $arguments['step_minutes'] ?? null;
		if (!is_int($duration) || $duration < 15 || $duration > 240 || !in_array($step, [15, 30], true)) {
			throw new CalendarArgumentException(Translator::t('Use a duration of 15–240 minutes and a step of 15 or 30 minutes.'));
		}
		$hours = $arguments['working_hours'] ?? [];
		$weekdays = $hours['weekdays'] ?? [];
		if (!is_array($weekdays) || !array_is_list($weekdays) || count($weekdays) < 1 || count($weekdays) > 7) {
			throw new CalendarArgumentException(Translator::t('Provide distinct working weekdays from 1 (Monday) to 7 (Sunday).'));
		}
		foreach ($weekdays as $weekday) {
			if (!is_int($weekday) || $weekday < 1 || $weekday > 7) throw new CalendarArgumentException(Translator::t('Provide distinct working weekdays from 1 (Monday) to 7 (Sunday).'));
		}
		if (count(array_unique($weekdays)) !== count($weekdays)) throw new CalendarArgumentException(Translator::t('Provide distinct working weekdays from 1 (Monday) to 7 (Sunday).'));
		$workStart = $this->minute($hours['start'] ?? null, false);
		$workEnd = $this->minute($hours['end'] ?? null, true);
		if ($workEnd <= $workStart) throw new CalendarArgumentException(Translator::t('Working hours must be an increasing same-day interval; 24:00 is allowed only as the end.'));
		$index = $arguments['next_index'] ?? 0;
		if (!is_int($index) || $index < 0 || $index > 1024) throw new CalendarArgumentException(Translator::t('Provide a valid next candidate index.'));
		return compact('from', 'to', 'zone', 'duration', 'step', 'weekdays', 'workStart', 'workEnd', 'index');
	}

	/**
	 * @param mixed $value explicit HH:MM wall time
	 * @param bool $end whether 24:00 may close the interval
	 * @return int minutes after local midnight
	 * @throws CalendarArgumentException on malformed hours
	 */
	private function minute(mixed $value, bool $end): int {
		if ($end && $value === '24:00') return 1440;
		if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) {
			throw new CalendarArgumentException(Translator::t('Working hours must be an increasing same-day interval; 24:00 is allowed only as the end.'));
		}
		return (int)substr($value, 0, 2) * 60 + (int)substr($value, 3, 2);
	}
}
