<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\References;

use OCA\Mcp\L10n\Translator;

final class ReferenceMessages
{
    /**
     * Label of the line a Deck reference adds to a message, before the title of the item.
     * @return string The label in the language of the current user.
     */
    public static function referenceLabelDeck(): string
    {
        return Translator::t('Deck card');
    }

    /**
     * Label of the line a calendar reference adds to a message, before the title of the item.
     * @return string The label in the language of the current user.
     */
    public static function referenceLabelCalendar(): string
    {
        return Translator::t('Calendar event');
    }

    /** Title shown for an event without SUMMARY. @return string The title in the language of the current user. */
    public static function referenceUntitled(): string
    {
        return Translator::t('(no title)');
    }

    /**
     * The referenced Deck card or calendar event does not exist or is not readable.
     * @return string The message in the language of the current user.
     */
    public static function referenceNotFound(): string
    {
        return Translator::t('referenced item not found or not accessible');
    }

    /**
     * The Deck app is off for this account, or the MCP has no read grant in Deck.
     * @return string The message in the language of the current user.
     */
    public static function referenceDeckOff(): string
    {
        return Translator::t(
            'Deck reference unavailable: the Deck app is turned off for you or the MCP has no read permission in Deck',
        );
    }

    /**
     * The Calendar app is off for this account, or the MCP has no read grant in Calendar.
     * @return string The message in the language of the current user.
     */
    public static function referenceCalendarOff(): string
    {
        return Translator::t(
            'Calendar reference unavailable: the Calendar app is turned off for you or the MCP has no read permission in Calendar',
        );
    }

    /**
     * The reference object does not follow {type: deck_card|calendar_event, ...}.
     * @return string The message in the language of the current user.
     */
    public static function invalidReference(): string
    {
        return Translator::t(
            'invalid reference: use {type: deck_card, card_id, board_id?} or {type: calendar_event, calendar, uid}',
        );
    }
}
