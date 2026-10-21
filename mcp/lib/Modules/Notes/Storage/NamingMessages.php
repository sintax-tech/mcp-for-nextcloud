<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Storage;

use OCA\Mcp\L10n\Translator;
class NamingMessages
{

    /**
     * Renaming a note onto a title already used in the same category.
     * @return string The message in the language of the current user.
     */

    public static function titleExistsInCategory(): string
    {
        return Translator::t('A note with this title already exists in this category.');
    }

    /**
     * Moving a note onto a title already used in the target category.
     * @return string The message in the language of the current user.
     */

    public static function titleExistsInTargetCategory(): string
    {
        return Translator::t('A note with this title already exists in the destination category.');
    }

    /**
     * The title already has the maximum number of copies with a " (n)" suffix.
     * @return string The message in the language of the current user.
     */

    public static function tooManyNotesWithSameTitle(): string
    {
        return Translator::t('There are already too many notes with this title.');
    }
}
