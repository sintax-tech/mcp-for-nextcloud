<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\L10n\Translator;

final class ImageMessages {
    /**
     * @param string $value value that failed to parse
     * @return string the modified_after/modified_before was not a valid ISO 8601 date
     */
    public static function invalidDate(string $value): string {
        return Translator::t('Invalid date: %s. Use ISO 8601, e.g. 2026-01-31 or 2026-01-31T12:00:00Z.', [$value]);
    }

    /** @return string a batch call with neither paths nor folder */
    public static function imagesNoTarget(): string {
        return Translator::t('Provide either paths or folder.');
    }

    /** @return string a batch call with both paths and folder */
    public static function imagesConflict(): string {
        return Translator::t('Pass either paths or folder, not both.');
    }

    /** @return string whole image preview budget exhausted and the file would not fit */
    public static function imagePreviewTooLarge(): string {
        return Translator::t('Preview still exceeds the byte budget after reduction; try a smaller max_size.');
    }

    /** @return string a file that is not supported by any preview provider and not a renderable raw image */
    public static function imageUnsupported(): string {
        return Translator::t('No image preview is available for this file.');
    }

    /** @return string a path that is not an image file */
    public static function notAnImage(): string {
        return Translator::t('The specified path is not an image file.');
    }

    /** @return string description of files_image_view */
    public static function imageViewTool(): string {
        return 'Fetch a reduced preview of an image file as inline image bytes, so the model can actually see it. '
            . 'Reads only; honors the user folder and permissions. Supports JPG, PNG, HEIC, WebP, GIF, TIFF and the '
            . 'first page of a PDF when the Nextcloud server has a preview provider for the format. The returned '
            . 'image is JPEG/PNG/WebP under a server-wide byte limit: if the preview is still too big the max '
            . 'dimension is reduced until it fits.';
    }

    /** @return string description of the max_size parameter of files_image_view */
    public static function imageMaxSize(): string {
        return 'Maximum width or height of the returned preview, in pixels (256-2048, default 1568)';
    }

    /** @return string description of files_images_view */
    public static function imagesViewTool(): string {
        return 'Fetch several image previews in a single call. Pass either paths (up to 6 items) or folder (plus '
            . 'an optional limit) to pick the most recently modified images under that folder. The total image '
            . 'bytes stay under a server-wide budget; images that do not fit are reported as skipped with the '
            . 'reason, so the agent knows what was left out.';
    }

    /** @return string description of the paths parameter of files_images_view */
    public static function imagePaths(): string {
        return 'Image paths, 1 to 6, e.g. ["/Photos/a.jpg", "/Photos/b.png"]';
    }

    /** @return string description of the folder parameter of files_images_view and files_image_search */
    public static function imageFolder(): string {
        return 'Folder to look in, e.g. /Photos; omit to search the whole user folder';
    }

    /** @return string description of files_image_search */
    public static function imageSearchTool(): string {
        return 'Search image files in the user folder, optionally filtered by name query, folder, modification '
            . 'date range or system tag name (the tag names created by the Recognize app count too). Results '
            . 'are listed newest first and only contain the file metadata; use files_image_view to fetch bytes.';
    }

    /** @return string description of the query parameter of files_image_search */
    public static function imageQuery(): string {
        return 'Optional name substring, e.g. "beach"';
    }

    /** @return string description of modified_after/modified_before */
    public static function imageModifiedAfter(): string {
        return 'ISO 8601 date or datetime, only images modified at or after this moment are returned';
    }

    /** @return string description of modified_before */
    public static function imageModifiedBefore(): string {
        return 'ISO 8601 date or datetime, only images modified at or before this moment are returned';
    }

    /** @return string description of the tag parameter of files_image_search */
    public static function imageTag(): string {
        return 'System tag name (case-sensitive), including automatic tags created by the Recognize app';
    }
}
