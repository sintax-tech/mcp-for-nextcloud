<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\L10n\Translator;

class StructureMessages {
    /**
     * @return string The message in the language of the current user.
     */
    public static function planCreateBoard(): string {
        return Translator::t('Nothing was changed. After your approval the board below is created for you, with its lists and cards, in this order. Nothing is undone if a step fails midway; the result says what exists.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planCreateStack(): string {
        return Translator::t('Nothing was changed. After your approval a new list is created on the board below.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planDeleteStack(): string {
        return Translator::t('Nothing was changed. After your approval the empty list below is deleted.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planDeleteBoard(): string {
        return Translator::t('Nothing was changed. After your approval the empty board below is deleted.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planDeleteStackConsequence(): string {
        return Translator::t('The list goes to the trash of the board and can be recovered there, in the Deck web interface.');
    }

    /**
     * @return string The message in the language of the current user.
     */
    public static function planDeleteBoardConsequence(): string {
        return Translator::t('The board, with its empty lists, goes to the Deck trash and can be recovered there, in the Deck web interface.');
    }
}
