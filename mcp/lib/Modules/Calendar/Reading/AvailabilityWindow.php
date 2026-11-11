<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;

/** Strict offset timestamps prevent silently normalizing invalid dates or guessing a DST fold. */
final class AvailabilityWindow {
	/**
	 * @param array<string, mixed> $arguments explicit start, end and timezone
	 * @return array{DateTimeImmutable, DateTimeImmutable, DateTimeZone} validated instants and display zone
	 * @throws CalendarArgumentException for malformed or over-budget windows
	 */
	public function parse(array $arguments): array {
		$zone = $arguments['timezone'] ?? '';
		if (!is_string($zone) || !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
			throw new CalendarArgumentException(Translator::t('Provide an explicit IANA timezone.'));
		}
		$start = $this->instant($arguments['start'] ?? null);
		$end = $this->instant($arguments['end'] ?? null);
		$seconds = $end->getTimestamp() - $start->getTimestamp();
		if ($seconds < 900 || $seconds > 14400) {
			throw new CalendarArgumentException(Translator::t('The availability window must last 15–240 minutes.'));
		}
		return [$start, $end, new DateTimeZone($zone)];
	}

	/**
	 * @param mixed $value caller timestamp
	 * @return DateTimeImmutable exact instant; offsets are never inferred from account settings
	 * @throws CalendarArgumentException on invalid dates, times or offsets
	 */
	private function instant(mixed $value): DateTimeImmutable {
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) {
			throw new CalendarArgumentException(Translator::t('Provide ISO timestamps with seconds and an explicit offset.'));
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
		$errors = DateTimeImmutable::getLastErrors();
		if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
			throw new CalendarArgumentException(Translator::t('Provide valid ISO timestamps.'));
		}
		return $date;
	}
}
