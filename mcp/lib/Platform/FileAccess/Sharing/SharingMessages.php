<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess\Sharing;

use OCA\Mcp\L10n\Translator;

/** Shared SharingMessages literals with stable translations. */
final class SharingMessages {
    /** @return string translated fallback label when a share has no identifiable sharer */
    public static function unidentified(): string {
        return Translator::t('unidentified');
    }

    /**
     * @param string $teamFolder name of the team folder the node lives in
     * @return string the sentence naming a team folder
     */
    public static function teamFolder(string $teamFolder): string {
        return Translator::t('This file is in the team folder \'%s\' (Team Folder).', [$teamFolder]);
    }

    /**
     * @param string $sharedBy display name of whoever shared the node
     * @return string the sentence naming the sharer
     */
    public static function sharedBy(string $sharedBy): string {
        return Translator::t('This file was shared by %s.', [$sharedBy]);
    }

    /** @return string the sentence for a node in an external storage, which has no person to name */
    public static function externalStorage(): string {
        return Translator::t('This file is on an external storage.');
    }

    public const UNIDENTIFIED = 'unidentified';
}
