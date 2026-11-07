<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Throwable;

/** Calendar timing with the existing Intl output and an extension-independent local ISO fallback. */
final class CalendarTimingFormatter {
	/** @param bool $useIntl false forces the supported fallback for deterministic tests */
	public function __construct(private bool $useIntl = true) {
	}

	/**
	 * @param mixed $startVal event start, as a civil date or timestamp
	 * @param mixed $endVal exclusive end
	 * @param bool $allDay whether times must be omitted
	 * @param string|null $tzStr explicit event timezone
	 * @param string|null $accountTz account timezone for events without their own zone
	 * @param string $locale request locale, unchanged for Intl
	 * @return string|null local readable interval, or null for missing/invalid input
	 */
	public function format(mixed $startVal, mixed $endVal, bool $allDay, ?string $tzStr, ?string $accountTz, string $locale): ?string {
		if (!is_string($startVal) || !is_string($endVal) || $startVal === '' || $endVal === '') {
			return null;
		}

		if ($allDay) {
			$startSub = substr($startVal, 0, 10);
			$endSub = substr($endVal, 0, 10);
			$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startSub, new DateTimeZone('UTC'));
			$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endSub, new DateTimeZone('UTC'));
			if ($start === false || $end === false || $end < $start) {
				return null;
			}

			$diff = $start->diff($end);
			$days = $diff->days;

			$formatDate = $this->dateFormatter($locale, new DateTimeZone('UTC'));

			// Single-day all-day event (end is exclusive, duration <= 1 day)
			if ($days <= 1) {
				return $formatDate($start);
			}

			// Multi-day all-day event (exclusive end date minus 1 day)
			$inclusiveEnd = $end->modify('-1 day');
			return $formatDate($start) . ' – ' . $formatDate($inclusiveEnd);
		}

		$tz = null;
		if ($tzStr !== null && $tzStr !== '') {
			try {
				$tz = new DateTimeZone($tzStr);
			} catch (Throwable) {
				return null;
			}
		}
		$tzTarget = $tz ?? ($accountTz !== null ? new DateTimeZone($accountTz) : new DateTimeZone(date_default_timezone_get() ?: 'UTC'));

		try {
			$start = new DateTimeImmutable($startVal);
			$end = new DateTimeImmutable($endVal);
		} catch (Throwable) {
			return null;
		}

		if ($end < $start) {
			return null;
		}

		$start = $start->setTimezone($tzTarget);
		$end = $end->setTimezone($tzTarget);

		$formatDate = $this->dateFormatter($locale, $tzTarget);
		$formatTime = $this->timeFormatter($locale, $tzTarget);

		// The account's own zone is the default the person reads in; any other zone is named.
		$tzSuffix = $tzStr !== null && $tzStr !== '' && $tzStr !== $accountTz ? ' (' . $tzStr . ')' : '';

		// Same day in the event timezone
		if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
			$timePart = $start->getTimestamp() === $end->getTimestamp()
				? $formatTime($start)
				: $formatTime($start) . '–' . $formatTime($end);
			return $formatDate($start) . ', ' . $timePart . $tzSuffix;
		}

		// Multi-day timed event
		return $formatDate($start) . ', ' . $formatTime($start) . ' – ' . $formatDate($end) . ', ' . $formatTime($end) . $tzSuffix;
	}

	/**
	 * @param string $locale request locale
	 * @param DateTimeZone $zone event or civil-date timezone
	 * @return Closure(DateTimeImmutable): string date formatter without a dependency on Intl
	 */
	private function dateFormatter(string $locale, DateTimeZone $zone): Closure {
		if (!$this->useIntl || !class_exists(IntlDateFormatter::class)) {
			return static fn (DateTimeImmutable $date): string => $date->format('Y-m-d');
		}
		$weekday = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $zone, null, 'EEE');
		$date = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $zone);
		return static fn (DateTimeImmutable $value): string => rtrim((string)$weekday->format($value), '.') . ', ' . $date->format($value);
	}

	/**
	 * @param string $locale request locale
	 * @param DateTimeZone $zone event timezone
	 * @return Closure(DateTimeImmutable): string local time formatter
	 */
	private function timeFormatter(string $locale, DateTimeZone $zone): Closure {
		if (!$this->useIntl || !class_exists(IntlDateFormatter::class)) {
			return static fn (DateTimeImmutable $date): string => $date->format('H:i');
		}
		$time = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $zone);
		return static fn (DateTimeImmutable $date): string => (string)$time->format($date);
	}
}
