<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use OCA\Mcp\L10n\Translator;

final class MessageMessages
{
    /**
     * Talk refused the message.
     * @return string The message in the language of the current user.
     */
    public static function messageNotSent(): string
    {
        return Translator::t('the message could not be sent');
    }

    /**
     * The message body is missing or blank.
     * @return string The message in the language of the current user.
     */
    public static function emptyMessage(): string
    {
        return Translator::t('message cannot be empty');
    }

    /**
     * The batch carries no message at all.
     * @return string The message in the language of the current user.
     */
    public static function emptyBatch(): string
    {
        return Translator::t('send at least one message in messages');
    }

    /**
     * The batch is longer than the module accepts.
     *
     * @param int $max Largest batch accepted
     * @return string The message in the language of the current user
     */
    public static function tooManyMessages(int $max): string
    {
        return Translator::t('batch with more than %d messages', [$max]);
    }

    /**
     * The message body is over the module character limit.
     * @return string The message in the language of the current user.
     */
    public static function messageTooLong(): string
    {
        return Translator::t('message exceeds the character limit');
    }

    /**
     * A reply or attachment id is not a positive integer.
     * @return string The message in the language of the current user.
     */
    public static function invalidIdentifier(): string
    {
        return Translator::t('invalid identifier');
    }

    /**
     * The quoted message does not exist in this conversation.
     * @return string The message in the language of the current user.
     */
    public static function replyTargetNotFound(): string
    {
        return Translator::t('quoted message not found in this conversation');
    }
}
