<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\DueDates;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Strict Deck deadline interpretation, with UTC wire values for its timezone-less datetime binding.
 */
final class CardDueDate {
	/**
	 * Explicit UTC wire form; native Deck discards offsets when binding its datetime column.
	 */
	private const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Calendar-day syntax retained for compatible clients.
	 */
	private const DAY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

	/**
	 * Minute precision, optional seconds and a bounded RFC-style numeric offset; no PHP free-form parser.
	 */
	private const TIME_PATTERN = '/^(?<local>\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::(?<seconds>\d{2}))?(?<offset>Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?$/D';

	/**
	 * Local clock form used for strict round trips and transition candidate matching.
	 */
	private const LOCAL_FORMAT = 'Y-m-d\TH:i:s';

	/**
	 * Two days on either side of a wall-clock reference include all relevant zone offsets.
	 */
	private const TRANSITION_WINDOW_SECONDS = 172800;

	/**
	 * Validate public input while retaining its spelling for the existing plan payload.
	 *
	 * @param mixed $value Calendar day, supported timestamp or null.
	 * @param DateTimeZone|null $zone Account timezone; PHP's default for isolated callers without an account.
	 * @return string|null Validated input, or null to remove the deadline.
	 * @throws InvalidArgumentException When format, date, time or local clock existence is invalid.
	 */
	public static function validate(mixed $value, ?DateTimeZone $zone = null): ?string {
		if ($value !== null && !is_string($value)) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\DueDates\CardDueDateMessages::invalid());
		}
		self::parse($value, $zone ?? new DateTimeZone(date_default_timezone_get()));
		return $value;
	}

	/**
	 * Interpret a supported deadline without allowing PHP to normalize invalid public input.
	 *
	 * Date-only midnight keeps the DK-7a native gap behavior. An explicitly requested local time
	 * must exist; if repeated, its earliest instant is selected independently of PHP's fold choice.
	 * An explicit -00:00 offset is normalized to UTC, like Z and +00:00.
	 *
	 * @param string|null $value Calendar day, local clock time, offset timestamp, or null.
	 * @param DateTimeZone $zone Account timezone for input without an offset.
	 * @return DateTimeImmutable|null The requested instant, or null.
	 * @throws InvalidArgumentException When input is malformed or a local time does not exist.
	 */
	public static function parse(?string $value, DateTimeZone $zone): ?DateTimeImmutable {
		if ($value === null) {
			return null;
		}
		if (preg_match(self::DAY_PATTERN, $value) === 1) {
			self::checked($value, 'Y-m-d');
			return self::midnight($value, $zone);
		}
		if (preg_match(self::TIME_PATTERN, $value, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\DueDates\CardDueDateMessages::invalid());
		}
		$local = $parts['local'] . ':' . ($parts['seconds'] ?? '00');
		$offset = $parts['offset'];
		if ($offset !== null) {
			return self::checked($local . (in_array($offset, ['Z', '-00:00'], true) ? '+00:00' : $offset), self::LOCAL_FORMAT . 'P');
		}
		$candidates = self::localCandidates($local, $zone);
		if ($candidates === []) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\DueDates\CardDueDateMessages::nonexistent());
		}
		return $candidates[0];
	}

	/**
	 * Convert every non-null supported deadline to UTC before native CardService create/update.
	 *
	 * @param string|null $value Public deadline or an existing native instant preserved by an edit.
	 * @param DateTimeZone $zone Account timezone used for a calendar day or an offset-free time.
	 * @return string|null UTC with Z and seconds, or null.
	 */
	public static function forDeck(?string $value, DateTimeZone $zone): ?string {
		return self::parse($value, $zone)?->setTimezone(new DateTimeZone('UTC'))->format(self::UTC_FORMAT);
	}

	/**
	 * Interpret a validated day once for both plans and native UTC storage.
	 *
	 * Date-only input retains PHP/tzdata midnight normalization from DK-7a. Strict gap rejection
	 * applies to an explicit local time instead; no historical date is rewritten.
	 *
	 * @param string $day Validated YYYY-MM-DD calendar day.
	 * @param DateTimeZone $zone Account timezone, including the offset in effect on that date.
	 * @return DateTimeImmutable Native midnight interpretation in the account timezone.
	 */
	public static function midnight(string $day, DateTimeZone $zone): DateTimeImmutable {
		return new DateTimeImmutable($day . ' 00:00:00', $zone);
	}

	/**
	 * Compare a native read-back with the requested deadline at the second, including explicit clearing.
	 *
	 * @param DateTimeInterface|null $actual Native persisted instant.
	 * @param string|null $requested Supported deadline sent to the write.
	 * @param DateTimeZone $zone Account timezone for input without an offset.
	 * @return bool Whether both have the same timestamp or are both absent.
	 */
	public static function same(?DateTimeInterface $actual, ?string $requested, DateTimeZone $zone): bool {
		$expected = self::parse($requested, $zone);
		return $actual?->getTimestamp() === $expected?->getTimestamp();
	}

	/**
	 * Determine whether a displayed local clock time has more than one possible instant.
	 *
	 * @param DateTimeInterface $instant Deadline to display.
	 * @param DateTimeZone $zone Display/account timezone.
	 * @return bool Whether an offset must disambiguate a repeated local clock time.
	 */
	public static function ambiguous(DateTimeInterface $instant, DateTimeZone $zone): bool {
		$local = DateTimeImmutable::createFromInterface($instant)->setTimezone($zone)->format(self::LOCAL_FORMAT);
		return count(self::localCandidates($local, $zone)) > 1;
	}

	/**
	 * Parse exact fields in UTC or the explicit offset and reject calendar/time overflow.
	 *
	 * @param string $value Fully padded fields, with offset when the format includes P.
	 * @param string $format Exact PHP date format for the supplied fields.
	 * @return DateTimeImmutable Validated instant or wall-clock reference.
	 * @throws InvalidArgumentException When PHP reports errors, warnings or normalized fields.
	 */
	private static function checked(string $value, string $format): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
		$errors = DateTimeImmutable::getLastErrors();
		if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
			|| $date->format($format) !== $value) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\DueDates\CardDueDateMessages::invalid());
		}
		return $date;
	}

	/**
	 * Resolve local fields against all nearby offsets, refusing gaps and sorting folds earliest first.
	 *
	 * @param string $local Validated-shape local fields including seconds.
	 * @param DateTimeZone $zone Account timezone, including fixed-offset zones.
	 * @return list<DateTimeImmutable> Zero candidates for a gap, one normally, two for a repeated time.
	 */
	private static function localCandidates(string $local, DateTimeZone $zone): array {
		$wall = self::checked($local, self::LOCAL_FORMAT);
		$timestamp = $wall->getTimestamp();
		$transitions = $zone->getTransitions($timestamp - self::TRANSITION_WINDOW_SECONDS, $timestamp + self::TRANSITION_WINDOW_SECONDS);
		$offsets = $transitions === false ? [$zone->getOffset($wall)] : array_column($transitions, 'offset');
		$found = [];
		foreach (array_unique($offsets) as $offset) {
			$candidate = $wall->setTimestamp($timestamp - $offset)->setTimezone($zone);
			if ($candidate->format(self::LOCAL_FORMAT) === $local) {
				$found[] = $candidate;
			}
		}
		usort($found, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());
		return $found;
	}
}
