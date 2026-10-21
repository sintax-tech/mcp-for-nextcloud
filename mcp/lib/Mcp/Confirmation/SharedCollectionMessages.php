<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Mcp\Confirmation;

use OCA\Mcp\L10n\Translator;
final class SharedCollectionMessages
{
    public static function sharedConfirmation(string $calendar, string $owner): string
    {
        return Translator::t('The calendar \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.', [$calendar, $owner]);
    }
}
