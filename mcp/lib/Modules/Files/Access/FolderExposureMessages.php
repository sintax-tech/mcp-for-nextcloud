<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Access;

use OCA\Mcp\L10n\Translator;

final class FolderExposureMessages {
    /** Refuse operations that could expose an unverified subtree. */
    public static function folderExposureRefused(): string {
        return Translator::t('Folders cannot be shared, copied or moved to another directory through this connector while files are excluded from MCP access. Use the Nextcloud Files interface for these folder operations.');
    }
}
