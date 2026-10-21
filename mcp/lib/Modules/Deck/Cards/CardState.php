<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Deck\Db\Card;

class CardState {
    private const DATE_FORMAT = 'Y-m-d';
    /**
     * Whether a card is still open: not finished and not archived.
     *
     * @param Card $card Card to classify.
     * @return bool True when nothing marks it as done or archived.
     */
    public static function isOpen(Card $card): bool {
        return $card->getDone() === null && !$card->getArchived();
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
    public static function isOverdue(
        Card $card,
        int $now,
        \DateTimeZone $zone,
    ): bool {
        $dueDay = CardDate::dueDay($card, $zone);
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->format(self::DATE_FORMAT);
        return self::isOpen($card) && $dueDay !== null && $dueDay < $today;
    }
}
