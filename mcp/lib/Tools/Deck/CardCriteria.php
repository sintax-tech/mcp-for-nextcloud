<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use InvalidArgumentException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;

/**
 * The rules that decide which cards a Deck read answers with, and how a card is labelled.
 *
 * Two callers share them so a card can never be "atrasado" in one place and not in the other: the
 * gateway filters with {@see self::matches()} while it queries, and `CardFormatter` labels the
 * payload with {@see self::isOverdue()} and {@see self::assignedUids()}. The rules only read what
 * Deck already stores (`done`, `archived`, `duedate` and the assignments, Deck `v1.17.5`), never
 * reach for a service, so they stay free of I/O and can be tested with a plain entity.
 *
 * Every day is the day the user sees: Deck keeps `duedate` in UTC, so near midnight the UTC calendar
 * and the user's one disagree, and a card due tonight in São Paulo would already read as tomorrow.
 * The caller passes the user's timezone ({@see \OCA\Mcp\Service\UserTimezone}).
 */
final class CardCriteria {
	/** Card due before today that was neither finished nor archived. */
	public const STATUS_OVERDUE = 'overdue';

	/** Card that still has to be done: not finished and not archived. */
	public const STATUS_OPEN = 'open';

	/** Card Deck stamped with a `done` timestamp. */
	public const STATUS_DONE = 'done';

	/** Every card, whatever its state. */
	public const STATUS_ALL = 'all';

	/** Statuses the follow-up accepts, in the order the tool schema lists them. */
	public const STATUSES = [
		self::STATUS_OVERDUE,
		self::STATUS_OPEN,
		self::STATUS_DONE,
		self::STATUS_ALL,
	];

	/** Day form every date comparison speaks, matching the `YYYY-MM-DD` the tools accept. */
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * Whether a card passes the status filter of a follow-up.
	 *
	 * @param Card $card Card to classify.
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string|null $dueBefore Keep only cards due on or before this `YYYY-MM-DD`, null for any date.
	 * @param int $now Current unix timestamp, taken from `ITimeFactory`.
	 * @param \DateTimeZone $zone Timezone of the user, deciding which day a due date falls on.
	 * @return bool True when the card belongs in the answer.
	 * @throws InvalidArgumentException When the status is not one of {@see self::STATUSES}.
	 */
	public static function matches(Card $card, string $status, ?string $dueBefore, int $now, \DateTimeZone $zone): bool {
		if (!in_array($status, self::STATUSES, true)) {
			throw new InvalidArgumentException(DeckMessages::ERROR_INVALID_STATUS);
		}

		if ($dueBefore !== null) {
			$dueDay = self::dueDay($card, $zone);
			if ($dueDay === null || $dueDay > $dueBefore) {
				return false;
			}
		}

		return match ($status) {
			self::STATUS_OVERDUE => self::isOverdue($card, $now, $zone),
			self::STATUS_OPEN => self::isOpen($card),
			self::STATUS_DONE => $card->getDone() !== null,
			default => true,
		};
	}

	/**
	 * Whether a card is late: due before today, still open.
	 *
	 * The comparison is by day, not by hour, so a card due today stays `open` until tomorrow; that
	 * is the same `YYYY-MM-DD` the tools speak and the same boundary `dueBefore` uses.
	 *
	 * @param Card $card Card to classify.
	 * @param int $now Current unix timestamp, taken from `ITimeFactory`.
	 * @param \DateTimeZone $zone Timezone of the user, deciding when "today" starts.
	 * @return bool True when the due date has already passed.
	 */
	public static function isOverdue(Card $card, int $now, \DateTimeZone $zone): bool {
		$dueDay = self::dueDay($card, $zone);
		$today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->format(self::DATE_FORMAT);

		return self::isOpen($card) && $dueDay !== null && $dueDay < $today;
	}

	/**
	 * The instant a `YYYY-MM-DD` written by the tools stands for: midnight of that day for the user.
	 *
	 * Deck parses the string with `new \DateTime()`, which reads the offset, so the card reads back as
	 * the same day in {@see self::dueDay()}. A bare day would be parsed as UTC midnight and show up as
	 * the previous day for anyone west of Greenwich.
	 *
	 * @param string $day Validated `YYYY-MM-DD`.
	 * @param \DateTimeZone $zone Timezone of the user.
	 * @return string ISO 8601 with the user's offset, e.g. `2026-10-01T00:00:00-03:00`.
	 */
	public static function localMidnight(string $day, \DateTimeZone $zone): string {
		return (new \DateTimeImmutable($day . ' 00:00:00', $zone))->format(\DateTimeInterface::ATOM);
	}

	/**
	 * UIDs of the people assigned to a card.
	 *
	 * Deck stores user, group and circle assignments in the same table (`Acl::PERMISSION_TYPE_*`);
	 * only a user assignment names a person, so groups and circles are left out instead of being
	 * answered as an "owner" that is not a user.
	 *
	 * @param Card $card Card whose assignments are already embedded, as the gateway does.
	 * @return list<string> UIDs, in the order Deck returned them.
	 */
	public static function assignedUids(Card $card): array {
		$uids = [];
		foreach ($card->getAssignedUsers() ?? [] as $assignment) {
			if ($assignment->getType() !== Acl::PERMISSION_TYPE_USER) {
				continue;
			}

			$uids[] = (string)$assignment->getParticipant();
		}

		return $uids;
	}

	/**
	 * Whether a card is still open: not finished and not archived.
	 *
	 * @param Card $card Card to classify.
	 * @return bool True when nothing marks it as done or archived.
	 */
	private static function isOpen(Card $card): bool {
		return $card->getDone() === null && !$card->getArchived();
	}

	/**
	 * Due date of a card as the day the user sees, or null when it has none.
	 *
	 * @param Card $card Card to read; Deck keeps `duedate` as a `datetime` column, but a stub or a
	 *     fresh entity may still hold the raw string, which is taken as a day already.
	 * @param \DateTimeZone $zone Timezone of the user.
	 * @return string|null Day in `YYYY-MM-DD`, or null.
	 */
	public static function dueDay(Card $card, \DateTimeZone $zone): ?string {
		$duedate = $card->getDuedate();
		if ($duedate instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($duedate)->setTimezone($zone)->format(self::DATE_FORMAT);
		}

		return is_string($duedate) && $duedate !== '' ? $duedate : null;
	}
}
