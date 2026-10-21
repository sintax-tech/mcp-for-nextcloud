<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\L10n\Translator;

final class ReadingMessages {
    /** @return string a path that is not a folder */
    public static function notAFolder(): string {
        return Translator::t('The specified path is not a folder.');
    }

    /** @return string appends to text cut by files_read */
    public static function textTruncated(): string {
        return Translator::t('[content truncated]');
    }

    /** @return string an unsupported format for text extraction */
    public static function unsupportedFormat(): string {
        return Translator::t('Unsupported file format for text extraction.');
    }

    /** @return string the file could not be opened */
    public static function openFailed(): string {
        return Translator::t('Could not open file.');
    }

    /** @return string the file could not be read */
    public static function readFailed(): string {
        return Translator::t('Could not read file.');
    }

    /**
     * @param int $limit read limit in bytes
     * @return string a file over the read limit
     */
    public static function readTooLarge(int $limit): string {
        return Translator::t('File exceeds the read limit of %s bytes.', [(string)$limit]);
    }

    /**
     * @param string $name file name
     * @return string a corrupt document whose text could not be extracted
     */
    public static function notExtracted(string $name): string {
        return Translator::t('[could not extract text from %s]', [$name]);
    }

    /** @return string notice for a PDF or image without text when Workflow OCR is active */
    public static function noTextOcrActive(): string {
        return Translator::t('This file still has no text. Workflow OCR processes files in the background, following the rule the administrator configured.');
    }

    /** @return string notice for a PDF or image without text when Workflow OCR is not available */
    public static function noTextOcrInactive(): string {
        return Translator::t('This file has no text layer. Ask the administrator to install the Workflow OCR app, or view the page as an image with the image tools.');
    }

    /**
     * @param int $limit binary read limit in bytes
     * @return string a binary resource over the read limit
     */
    public static function binaryResourceTooLarge(int $limit): string {
        return Translator::t('Resource exceeds the binary read limit of %s bytes.', [(string)$limit]);
    }

    /** @return string description of files_read */
    public static function readTool(): string {
        return 'Read the text of a file from Nextcloud (plain text/markdown directly; PDF/DOCX/ODT via text extraction).';
    }

    /** @return string description of the path parameter */
    public static function path(): string {
        return 'Path of the file or folder, e.g. /Documents/report.pdf';
    }

    /** @return string description of files_list */
    public static function listTool(): string {
        return 'List files and folders from a user Nextcloud directory.';
    }

    /** @return string description of files_tree */
    public static function treeTool(): string {
        return 'List the file tree of a folder, with the owner of each item and capacity limits. '
            . 'Use to plan a reorganization without one call per folder. Enforces depth and entry '
            . 'limits, and warns when truncated.';
    }
    /** @return string a path that is not a file */
    public static function notAFile(): string {
        return Translator::t('The specified path is not a file.');
    }
}
