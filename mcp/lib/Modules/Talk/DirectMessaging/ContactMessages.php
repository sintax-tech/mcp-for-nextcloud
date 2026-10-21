<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\DirectMessaging;

use OCA\Mcp\L10n\Translator;

final class ContactMessages
{
    /**
     * The account does not exist or may not be messaged by this account.
     * @return string The message in the language of the current user.
     */
    public static function userNotReachable(): string
    {
        return Translator::t('user not found or no permission to message them');
    }

    /**
     * Refusal of one guest of a group.
     *
     * @param string $accountId Account id the caller sent
     * @return string The message in the language of the current user
     */
    public static function participantNotReachable(string $accountId): string
    {
        return Translator::t('participant \'%s\' not found or no permission to message them', [$accountId]);
    }

    /**
     * The account id is missing or is not a string.
     * @return string The message in the language of the current user.
     */
    public static function invalidUser(): string
    {
        return Translator::t('invalid user');
    }
}
