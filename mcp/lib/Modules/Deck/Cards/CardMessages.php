<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\L10n\Translator;

class CardMessages {
    /**
     * @return string The message in the language of the current user.
     */
    public static function planCreate(): string {
        return Translator::t('Nothing was changed. After your approval a new card is created at the end of the list below, owned by you. You are not assigned to it unless listed below.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planEdit(): string {
        return Translator::t('Nothing was changed. After your approval the fields below are written over the card.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planMove(): string {
        return Translator::t('Nothing was changed. After your approval the card moves from the list below to the destination below.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planDelete(): string {
        return Translator::t('Nothing was changed. After your approval the card is deleted from the board below.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planDeleteConsequence(): string {
        return Translator::t('Deck deletes the card the way the web interface does: it leaves the lists, and only the board owner or an administrator can bring it back from the Deck web interface. No tool of this app undoes it.');
    }
}
