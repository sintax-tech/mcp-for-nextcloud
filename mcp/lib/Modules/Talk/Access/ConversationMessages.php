<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Access;

use OCA\Mcp\L10n\Translator;

final class ConversationMessages
{
    /**
     * The conversation does not exist for this account, or it is not readable.
     * @return string The message in the language of the current user.
     */
    public static function conversationNotFound(): string
    {
        return Translator::t('conversation not found or not accessible');
    }

    /**
     * The conversation exists but this account may not write in it.
     * @return string The message in the language of the current user.
     */
    public static function conversationNotWritable(): string
    {
        return Translator::t('no permission to write in this conversation');
    }

    /**
     * The conversation token is missing or is not a string.
     * @return string The message in the language of the current user.
     */
    public static function invalidToken(): string
    {
        return Translator::t('invalid conversation_token');
    }

    /**
     * The limit argument is outside the accepted range.
     * @return string The message in the language of the current user.
     */
    public static function invalidLimit(): string
    {
        return Translator::t('limit must be a number between 1 and 200');
    }
}
