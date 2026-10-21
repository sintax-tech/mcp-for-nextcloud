<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\L10n\Translator;

final class CreationMessages {
    /** @return string the consequence of a new file, shared by files_upload and files_create */
    public static function planNewFileConsequence(): string {
        return Translator::t('Nothing is overwritten: if a file with this name already exists, with content, nothing is written.');
    }

    /** @return string the plan of files_upload */
    public static function planUpload(): string {
        return Translator::t('Nothing was changed. After your approval a single-use upload link is issued that creates this new file; the file only appears once the bytes are sent to it.');
    }

    /** @return string the confirmed files_upload, telling the agent what to do with the link */
    public static function uploadIssued(): string {
        return Translator::t('Send the file with the curl command below before the link expires. The link works once: if the upload is refused before it is used, fix the request and send it again; otherwise call files_upload again.');
    }

    /** @return string the plan of files_create */
    public static function planCreate(): string {
        return Translator::t('Nothing was changed. After your approval the new text file below is created.');
    }

    /** @return string a new file whose name is already taken */
    public static function fileExists(): string {
        return Translator::t('A file with this name already exists; use files_checkout to replace it or choose another name.');
    }

    /** @return string the write of a new file failed after the empty file may have appeared */
    public static function createFailed(): string {
        return Translator::t('Failed to write file; an empty file with this name may have been left in place.');
    }

    /** @return string the rule of a path whose extension files_create does not write */
    public static function createExtensionRule(): string {
        return Translator::t('files_create only writes text files (%s); use files_upload for any other type', [implode(', ', array_map(
            static fn (string $extension): string => '.' . $extension, FileCreation::TEXT_EXTENSIONS))]);
    }

    /**
     * @param int $limit maximum accepted size in bytes
     * @return string inline content over the files_create limit
     */
    public static function createTooLarge(int $limit): string {
        return Translator::t('Content exceeds the limit of %s bytes for files_create; use files_upload for a larger file.', [(string)$limit]);
    }

    /**
     * The folder of a new file is missing or hidden. Both answer the same, starting with the generic not-found,
     * so a hidden folder cannot be told apart from one that does not exist.
     *
     * @return string the refusal, with the way out for a folder that really is missing
     */
    public static function createFolderMissing(): string {
        return FileAccessMessages::notFound() . ' ' . Translator::t('If the destination folder does not exist yet, create it first with files_mkdir.');
    }

    /**
     * @param int $limit effective limit in bytes
     * @return string an upload over the limit
     */
    public static function uploadTooLarge(int $limit): string {
        return Translator::t('Content exceeds the upload limit of %s bytes.', [(string)$limit]);
    }

    /** @return string the rule of a path that names no file Nextcloud accepts */
    public static function newFileNameRule(): string {
        return Translator::t('must end in a file name Nextcloud accepts: no reserved name, forbidden character or extension, at most 250 bytes');
    }

    /** @return string description of files_upload, read by the model and therefore fixed English */
    public static function uploadTool(): string {
        return 'Create a NEW file of any type (docx, xlsx, pdf, image, zip) that you generated locally, without passing '
            . 'its content through the model. Without confirm: true it returns the plan; with confirm: true it returns '
            . 'uploadUrl, a single-use link valid for 15 minutes and bound to you and this path. Send the bytes with '
            . 'curl -sS -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL"; the answer '
            . 'carries path, size, etag and fileId. Never overwrites a file with content: an existing name is refused, so use '
            . 'files_checkout to replace an existing file. For a small text file use files_create. The folder must '
            . 'exist (files_mkdir creates it); outside your personal folder ask the user and retry with confirm_shared.';
    }

    /** @return string description of the path of files_upload and files_create */
    public static function newFilePath(): string {
        return 'Full path of the new file, including its name, e.g. /Reports/Report.docx';
    }

    /** @return string description of the size argument of files_upload */
    public static function uploadSize(): string {
        return 'Size of the local file in bytes, optional: a size over the upload limit is refused before any link is issued';
    }

    /** @return string description of files_create, read by the model and therefore fixed English */
    public static function createTool(): string {
        return 'Create a NEW small text file with its content inline: ' . implode(', ', array_map(
            static fn (string $extension): string => '.' . $extension, FileCreation::TEXT_EXTENSIONS))
            . ', up to 1 MB of UTF-8. Never overwrites a file with content: an existing name is refused (use files_edit or files_checkout '
            . 'to change it). For any other type or a larger file use files_upload. The folder must exist '
            . '(files_mkdir creates it); outside your personal folder ask the user and retry with confirm_shared.';
    }

    /** @return string description of the content argument of files_create */
    public static function createContent(): string {
        return 'Complete text of the new file, up to 1 MB of UTF-8';
    }
}
