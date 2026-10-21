<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Presentation;

use OCA\Mcp\L10n\Translator;
class NotesPlanMessages
{
    /* Plan of a write: the answer to a call that arrives without confirm, nothing having been written. */

    /**
     * @return string The message in the language of the current user.
     */

    public static function planCreate(): string
    {
        return Translator::t('Nothing was changed. After your approval a note is created with the title and content below.');
    }

    /**
     * @return string The message in the language of the current user.
     */

    public static function planEdit(): string
    {
        return Translator::t('Nothing was changed. After your approval the note keeps the fields below.');
    }

    /**
     * @return string The message in the language of the current user.
     */

    public static function planMove(): string
    {
        return Translator::t('Nothing was changed. After your approval the note moves to the category below.');
    }

    /**
     * @return string The message in the language of the current user.
     */

    public static function planDelete(): string
    {
        return Translator::t('Nothing was changed. After your approval the note goes to the Nextcloud trash bin.');
    }

    /**
     * @return string The message in the language of the current user.
     */

    public static function planDeleteConsequence(): string
    {
        return Translator::t('The note can be restored from the Nextcloud trash bin, where it stays until it is emptied.');
    }

    /**
     * Appended to the excerpt of a note that is longer than the plan shows.
     * @return string The message in the language of the current user.
     */

    public static function excerptTruncated(): string
    {
        return Translator::t('[content truncated]');
    }
}
