<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Followup;

use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;
use OCA\Mcp\Modules\Deck\Cards\CardDate;
use OCA\Mcp\Modules\Deck\Cards\CardState;
use InvalidArgumentException;
use OCA\Deck\Db\Card;
/**
 * The rules that decide which cards a Deck read answers with, and how a card is labelled.
 *
 * Two callers share them so a card can never be "atrasado" in one place and not in the other: the
 * gateway filters with {@see self::matches()} while it queries, and `CardFormatter` labels the
 * payload with {@see \OCA\Mcp\Modules\Deck\Cards\CardState::isOverdue()} and {@see self::assignedUids()}. The rules only read what
 * Deck already stores (`done`, `archived`, `duedate` and the assignments, Deck `v1.17.5`), never
 * reach for a service, so they stay free of I/O and can be tested with a plain entity.
 *
 * Every day is the day the user sees: Deck keeps `duedate` in UTC, so near midnight the UTC calendar
 * and the user's one disagree, and a card due tonight in São Paulo would already read as tomorrow.
 * The caller passes the user's timezone ({@see \OCA\Mcp\Platform\Time\UserTimezone}).
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
    public const STATUSES = [self::STATUS_OVERDUE, self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_ALL];
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
    public static function matches(
        Card $card,
        string $status,
        ?string $dueBefore,
        int $now,
        \DateTimeZone $zone,
    ): bool {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(ErrorMessages::errorInvalidStatus());
        }
        if ($dueBefore !== null) {
            $dueDay = CardDate::dueDay($card, $zone);
            if ($dueDay === null || $dueDay > $dueBefore) {
                return false;
            }
        }
        return match ($status) {
            self::STATUS_OVERDUE => CardState::isOverdue($card, $now, $zone),
            self::STATUS_OPEN => CardState::isOpen($card),
            self::STATUS_DONE => $card->getDone() !== null,
            default => true,
        };
    }
}
