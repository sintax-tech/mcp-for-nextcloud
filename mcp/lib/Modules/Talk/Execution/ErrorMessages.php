<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Execution;

use OCA\Mcp\L10n\Translator;

final class ErrorMessages
{
    /**
     * spreed is not installed or not enabled for this account.
     * @return string The message in the language of the current user.
     */
    public static function talkUnavailable(): string
    {
        return Translator::t('the conversations app is not available');
    }

    /**
     * Fallback for anything unexpected.
     * @return string The message in the language of the current user.
     */
    public static function unexpected(): string
    {
        return Translator::t('unexpected error while accessing Talk');
    }

    /**
     * Raised for a tool name this module does not serve.
     * @return string The message in the language of the current user.
     */
    public static function unknownTool(): string
    {
        return Translator::t('unknown tool');
    }
}
