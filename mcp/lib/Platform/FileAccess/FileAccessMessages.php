<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess;

use OCA\Mcp\L10n\Translator;

/** Shared FileAccessMessages literals with stable translations. */
final class FileAccessMessages {
    /** @return string translated message when a resource cannot be found in Nextcloud */
    public static function notFound(): string {
        return Translator::t('Resource not found in Nextcloud.');
    }

    /** @return string translated message when the user has no access to a resource */
    public static function forbidden(): string {
        return Translator::t('No access to this resource in Nextcloud.');
    }

    /** @return string translated retry message when another operation holds the resource lock */
    public static function locked(): string {
        return Translator::t('Resource locked by another operation; try again.');
    }

    /** @return string translated message when the resource ETag changed since it was read */
    public static function conflict(): string {
        return Translator::t('The resource has changed since the last read (etag differs); nothing was written.');
    }

    /** @return string insufficient quota */
    public static function insufficientQuota(): string {
        return Translator::t('Insufficient space in the user quota.');
    }

    /** @return string a node that is not a file */
    public static function notAFile(): string {
        return Translator::t('The specified path is not a file.');
    }

    /** @return string a file already exists with folder name */
    public static function fileInTargetFolderPath(): string {
        return Translator::t('A file already exists with the name of the destination folder.');
    }
}
