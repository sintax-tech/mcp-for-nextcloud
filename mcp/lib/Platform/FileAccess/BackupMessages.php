<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess;

use OCA\Mcp\L10n\Translator;

final class BackupMessages {
    /** @return string the backup copy could not be created or verified */
    public static function failed(): string {
        return Translator::t('Editing blocked: could not create backup copy of original.');
    }
}
