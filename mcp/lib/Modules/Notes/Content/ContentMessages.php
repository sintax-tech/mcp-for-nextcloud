<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Content;

use OCA\Mcp\L10n\Translator;
class ContentMessages
{

    /**
     * Note larger than MAX_BYTES when it is being read.
     * @param int $max limit in bytes
     * @return string The message in the language of the current user.
     */

    public static function noteTooLargeForReading(int $max): string
    {
        return Translator::t('Note exceeds the read limit of %d bytes.', [$max]);
    }

    /**
     * Note larger than MAX_BYTES when it is being written.
     * @param int $max limit in bytes
     * @return string The message in the language of the current user.
     */

    public static function noteTooLarge(int $max): string
    {
        return Translator::t('Note exceeds the limit of %d bytes.', [$max]);
    }
}
