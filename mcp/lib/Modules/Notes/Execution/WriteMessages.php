<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Execution;

use OCA\Mcp\L10n\Translator;
class WriteMessages
{

    /**
     * Deletion the trash bin would not take back, so it is refused.
     * @return string The message in the language of the current user.
     */

    public static function notRecoverable(): string
    {
        return Translator::t('Deletion blocked: the trash bin (files_trashbin) is not active for this note, so it would not be recoverable.');
    }

    /**
     * Closes the lock refusal of a rename that came after the new content of the same edit was saved.
     * @return string The message in the language of the current user.
     */

    public static function contentSavedTitleNot(): string
    {
        return Translator::t('The new content was saved; only the title was not changed.');
    }
}
