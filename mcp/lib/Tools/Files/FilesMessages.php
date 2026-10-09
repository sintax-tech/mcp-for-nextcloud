<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Common\CommonMessages;

/**
 * Every user-facing string of the Files module: tool descriptions, parameter descriptions and failure
 * messages. Handlers hold no text literal; this is the single entry point for IL10N.
 */
final class FilesMessages {
    /** Folder in the user's root that receives a copy of every file before an edit writes it. */
    public const BACKUP_FOLDER = FileBackup::FOLDER;

    // ---------------------------------------------------------------- tool descriptions

    /** @return string description of files_list */
    public static function listTool(): string {
        return 'List files and folders from a user Nextcloud directory.';
    }

    /** @return string description of files_search */
    public static function searchTool(): string {
        return 'Search files by name or content in the user folder on Nextcloud.';
    }

    /** @return string description of files_move_batch */
    public static function batchTool(): string {
        return 'Move several files and create folders, in the given order. Without confirm: true writes nothing '
            . 'and returns the full plan: what would succeed, what conflicts, what Nextcloud denies, what belongs '
            . 'to someone else, and which folders would be created. With confirm: true it executes in order, '
            . 'creates the folders beforehand and returns a batch_id to undo with files_undo_batch. An item '
            . 'failing midway halts the batch: items already moved remain recorded and can be undone, and '
            . 'unattempted items are returned as not_attempted.';
    }

    /** @return string description of files_undo_batch */
    public static function undoTool(): string {
        return 'Undo a files_move_batch, returning each node to its source path in reverse order. '
            . 'Before making any changes checks the whole batch: if any item is no longer what the '
            . 'batch placed at destination, if the source path is already occupied, or if permissions changed, '
            . 'nothing is undone and the response identifies the blocking item. Removes only empty folders that the '
            . 'batch itself created; a folder with content is preserved and listed in kept_dirs.';
    }

    /** @return string files_move_batch executed without confirm: true */
    public static function batchNeedsConfirm(): string {
        return Translator::t('files_move_batch with dry_run: false requires confirm: true.');
    }

    /** @return string an unexpected failure while moving one item of a batch */
    public static function moveFailed(): string {
        return Translator::t('Failed to move the item.');
    }

    /** @return string a batch that does not exist, is not this user's, or is past its lifetime */
    public static function batchNotFound(): string {
        return Translator::t('Batch not found.');
    }

    /** @return string a batch that was already undone */
    public static function batchAlreadyUndone(): string {
        return Translator::t('This batch has already been undone.');
    }

    /** @return string a node at the destination that is not the one the batch moved */
    public static function notTheBatchNode(): string {
        return Translator::t('The item is no longer what this batch moved; nothing was undone.');
    }

    /** @return string description of files_read */
    public static function readTool(): string {
        return 'Read the text of a file from Nextcloud (plain text/markdown directly; PDF/DOCX/ODT via text extraction).';
    }

    /** @return string description of files_tree */
    public static function treeTool(): string {
        return 'List the file tree of a folder, with the owner of each item and capacity limits. '
            . 'Use to plan a reorganization without one call per folder. Enforces depth and entry '
            . 'limits, and warns when truncated.';
    }

    /** @return string description of files_mkdir */
    public static function mkdirTool(): string {
        return 'Create a folder, including intermediate levels when missing. Refuses if destination '
            . 'already exists and never deletes existing contents. If destination is outside your personal '
            . 'folder, ask the user first and retry with confirm_shared.';
    }

    /** @return string description of files_copy */
    public static function copyTool(): string {
        return 'Copy a file or folder to another path, creating a new node with a new id. Never '
            . 'overwrites the destination. The copy is an independent node: does not preserve source id, versions, '
            . 'or shares. Capped at ' . ReorganizationLimits::NODES . ' items and '
            . ReorganizationLimits::BYTES . ' bytes, measured before copying. If source or destination '
            . 'is outside your personal folder, ask the user first and retry with confirm_shared.';
    }

    /** @return string description of files_move */
    public static function moveTool(): string {
        return 'Move or rename a file or folder within the same file area, without overwriting the '
            . 'destination. Reports id before and after, as well as version and share counts when '
            . 'corresponding apps are enabled. Does not move across different storages: there Nextcloud treats '
            . 'it as a copy and the id changes. If source or destination is outside your personal folder, ask '
            . 'the user first and retry with confirm_shared.';
    }

    /** @return string description of files_edit */
    public static function editTool(): string {
        return 'Replace the content of an existing text file and return the diff. Before writing, requires '
            . 'active versioning and creates a copy in "/MCP backups". If the file is outside your personal '
            . 'folder, only write after asking the user and retrying the call with confirm_shared. '
            . 'Never creates, moves, or deletes files.';
    }

    /** @return string description of files_replace */
    public static function replaceTool(): string {
        return 'Replace a unique snippet of an existing text file and return the diff. The specified snippet '
            . 'must appear exactly once. Before writing, requires active versioning and creates a copy '
            . 'in "/MCP backups". If the file is outside your personal folder, only write after '
            . 'asking the user and retrying the call with confirm_shared.';
    }

    /** @return string description of files_checkout */
    public static function checkoutTool(): string {
        return 'Provide temporary download and upload links to edit any file type (document, spreadsheet, PDF, '
            . 'image) with local tools (curl) without passing content through the model, up to the upload limit. '
            . 'Upload raw bytes with curl -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL". '
            . 'A text file can also be edited directly with files_edit or files_replace. Links are valid for 5 to 15 minutes, single-use, '
            . 'and bound to you, the file, and current ETag: if the file changes before upload, nothing is '
            . 'written. Upload creates the same backup as files_edit. If the file is outside your personal '
            . 'folder, only issue links after asking the user and retrying with confirm_shared.';
    }

    /** @return string description of files_versions_list */
    public static function versionsListTool(): string {
        return 'List versions of a file from the Versions app, from newest to oldest.';
    }

    /** @return string description of files_version_read */
    public static function versionReadTool(): string {
        return 'Read the text of a previous file version, with the same limit and text extraction as '
            . 'files_read.';
    }

    /** @return string description of files_version_restore */
    public static function versionRestoreTool(): string {
        return 'Restore a previous file version, first creating a copy of current content in '
            . '"/MCP backups". If the file is outside your personal folder, '
            . 'restore only after asking the user and retrying with confirm_shared.';
    }

    /** @return string description of files_image_view */
    public static function imageViewTool(): string {
        return 'Fetch a reduced preview of an image file as inline image bytes, so the model can actually see it. '
            . 'Reads only; honors the user folder and permissions. Supports JPG, PNG, HEIC, WebP, GIF, TIFF and the '
            . 'first page of a PDF when the Nextcloud server has a preview provider for the format. The returned '
            . 'image is JPEG/PNG/WebP under a server-wide byte limit: if the preview is still too big the max '
            . 'dimension is reduced until it fits.';
    }

    /** @return string description of files_images_view */
    public static function imagesViewTool(): string {
        return 'Fetch several image previews in a single call. Pass either paths (up to 6 items) or folder (plus '
            . 'an optional limit) to pick the most recently modified images under that folder. The total image '
            . 'bytes stay under a server-wide budget; images that do not fit are reported as skipped with the '
            . 'reason, so the agent knows what was left out.';
    }

    /** @return string description of files_image_search */
    public static function imageSearchTool(): string {
        return 'Search image files in the user folder, optionally filtered by name query, folder, modification '
            . 'date range or system tag name (the tag names created by the Recognize app count too). Results '
            . 'are listed newest first and only contain the file metadata; use files_image_view to fetch bytes.';
    }

    // ---------------------------------------------------------------- parameter descriptions

    /** @return string description of the path parameter */
    public static function path(): string {
        return 'Path of the file or folder, e.g. /Documents/report.pdf';
    }

    /** @return string description of the etag parameter */
    public static function etag(): string {
        return 'Previously read ETag; if it differs, nothing is written';
    }

    /** @return string description of the confirm_shared parameter */
    public static function confirmShared(): string {
        return 'Required only when the file is outside your personal folder (shared, team folder, '
            . 'or external storage). Send only after confirming with the user.';
    }

    /** @return string description of the content parameter of files_edit */
    public static function content(): string {
        return 'Complete new content (UTF-8)';
    }

    /** @return string description of the old parameter of files_replace */
    public static function oldSnippet(): string {
        return 'Snippet to replace; must appear exactly once in the file';
    }

    /** @return string description of the new parameter of files_replace */
    public static function newSnippet(): string {
        return 'Replacement text for the specified snippet';
    }

    /** @return string description of the version parameter */
    public static function version(): string {
        return 'Version identifier, as returned by files_versions_list';
    }

    /** @return string description of the search query parameter */
    public static function searchQuery(): string {
        return 'Search query to find in file names or content.';
    }

    /** @return string description of the search limit parameter */
    public static function searchLimit(): string {
        return 'Maximum number of results to return (1-100, default 25).';
    }

    /** @return string description of the search mode parameter */
    public static function searchMode(): string {
        return 'Search mode: "auto" uses full-text content search when available, falling back to name search; "name" searches only file names.';
    }

    /** @return string notice when full-text search is not available */
    public static function searchNameOnlyNotice(): string {
        return Translator::t('Full-text search is not available. Search was performed by file name only.');
    }

    /** @return string notice when full-text search threw an error and fell back */
    public static function searchFallbackNotice(): string {
        return Translator::t('Full-text search encountered an error; search was performed by file name only.');
    }

    // ---------------------------------------------------------------- plans of the writes

    /**
     * @return string the plan of files_mkdir
     */
    public static function planMkdir(): string {
        return Translator::t('Nothing was changed. After your approval the folders below are created.');
    }

    /**
     * @return string the plan of files_copy
     */
    public static function planCopy(): string {
        return Translator::t('Nothing was changed. After your approval the item below is copied to the destination, as a new independent copy.');
    }

    /**
     * @return string the plan of files_move
     */
    public static function planMove(): string {
        return Translator::t('Nothing was changed. After your approval the item below moves to the destination, keeping its id, its versions and its shares.');
    }

    /**
     * @return string the plan of files_move_batch
     */
    public static function planBatch(): string {
        return Translator::t('Nothing was changed. After your approval the items below are moved in the order shown and the folders are created. The run returns a batch_id that undoes it.');
    }

    /**
     * @return string the plan of files_undo_batch
     */
    public static function planUndoBatch(): string {
        return Translator::t('Nothing was changed. After your approval each item below goes back to where the batch took it from, and only the empty folders the batch created are removed.');
    }

    /**
     * @return string the plan of files_edit
     */
    public static function planEdit(): string {
        return Translator::t('Nothing was changed. After your approval the file is replaced by the content below and a copy of the current content is kept in the backup folder.');
    }

    /**
     * @return string the plan of files_replace
     */
    public static function planReplace(): string {
        return Translator::t('Nothing was changed. After your approval the snippet below is replaced and a copy of the current content is kept in the backup folder.');
    }

    /**
     * @return string the plan of files_checkout
     */
    public static function planCheckout(): string {
        return Translator::t('Nothing was changed. After your approval a single-use download link and a single-use upload link are issued for this file, which can be any file type (document, spreadsheet, PDF, image) up to the upload limit below; the upload only succeeds while the ETag below is the current one. A text file can also be edited directly with files_edit or files_replace.');
    }

    /**
     * @return string the plan of files_version_restore
     */
    public static function planRestore(): string {
        return Translator::t('Nothing was changed. After your approval a copy of the current content is kept in the backup folder and the content of the version below is written back to the file; Nextcloud versioning applies as to any edit, according to its policy.');
    }

    /**
     * @return string what the backup taken by a write gives back
     */
    public static function planBackupConsequence(): string {
        return Translator::t('The previous content stays available in the backup folder. Nextcloud may also keep versions of the file, according to its versioning policy.');
    }

    /**
     * @return string what the links of a checkout mean
     */
    public static function planCheckoutConsequence(): string {
        return Translator::t('The upload replaces the whole file, takes the same backup as an edit and is refused if the file changed since the checkout.');
    }

    /** @return string description of the max_size parameter of files_image_view */
    public static function imageMaxSize(): string {
        return 'Maximum width or height of the returned preview, in pixels (256-2048, default 1568)';
    }

    /** @return string description of the paths parameter of files_images_view */
    public static function imagePaths(): string {
        return 'Image paths, 1 to 6, e.g. ["/Photos/a.jpg", "/Photos/b.png"]';
    }

    /** @return string description of the folder parameter of files_images_view and files_image_search */
    public static function imageFolder(): string {
        return 'Folder to look in, e.g. /Photos; omit to search the whole user folder';
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

    // ---------------------------------------------------------------- image failures

    /** @return string a file that is not supported by any preview provider and not a renderable raw image */
    public static function imageUnsupported(): string {
        return Translator::t('No image preview is available for this file.');
    }

    /** @return string a path that is not an image file */
    public static function notAnImage(): string {
        return Translator::t('The specified path is not an image file.');
    }

    /** @return string a batch call with neither paths nor folder */
    public static function imagesNoTarget(): string {
        return Translator::t('Provide either paths or folder.');
    }

    /** @return string a batch call with both paths and folder */
    public static function imagesConflict(): string {
        return Translator::t('Pass either paths or folder, not both.');
    }

    /**
     * @param string $value value that failed to parse
     * @return string the modified_after/modified_before was not a valid ISO 8601 date
     */
    public static function invalidDate(string $value): string {
        return Translator::t('Invalid date: %s. Use ISO 8601, e.g. 2026-01-31 or 2026-01-31T12:00:00Z.', [$value]);
    }

    /** @return string whole image preview budget exhausted and the file would not fit */
    public static function imagePreviewTooLarge(): string {
        return Translator::t('Preview still exceeds the byte budget after reduction; try a smaller max_size.');
    }

    // ---------------------------------------------------------------- failures

    /** @return string a path that is not a folder */
    public static function notAFolder(): string {
        return Translator::t('The specified path is not a folder.');
    }

    /** @return string a path that is not a file */
    public static function notAFile(): string {
        return Translator::t('The specified path is not a file.');
    }

    /** @return string a non-text file */
    public static function notText(): string {
        return Translator::t('Only text files can be edited via MCP.');
    }

    /** @return string an edit inside the backup folder */
    public static function backupPath(): string {
        return Translator::t('Files in \'/%s\' cannot be edited via MCP.', [self::BACKUP_FOLDER]);
    }

    /**
     * @param int $limit maximum accepted size in bytes
     * @return string a content over the edit limit
     */
    public static function editTooLarge(int $limit): string {
        return Translator::t('Content exceeds the edit limit of %s bytes.', [(string)$limit]);
    }

    /**
     * @param string $backup path of the verified copy of the original
     * @return string a failed write, naming where the original still is
     */
    public static function writeFailed(string $backup): string {
        return Translator::t('Failed to write file; original preserved in %s.', [$backup]);
    }

    /**
     * @param string $backup user-relative path of the backup taken before the refused write
     * @return string closing sentence of a lock refusal that came after the backup
     */
    public static function originalKept(string $backup): string {
        return Translator::t('The original is kept in %s.', [$backup]);
    }

    /** @return string versioning is off for the user */
    public static function versioningOff(): string {
        return Translator::t('Editing blocked: file versioning (files_versions) is not active.');
    }

    /** @return string the backup copy could not be created or verified */
    public static function backupFailed(): string {
        return Translator::t('Editing blocked: could not create backup copy of original.');
    }

    /** @return string the snippet given to files_replace does not occur */
    public static function snippetMissing(): string {
        return Translator::t('The specified snippet does not appear in the file.');
    }

    /** @return string the snippet given to files_replace is not text we can locate in the file */
    public static function snippetNotUtf8(): string {
        return Translator::t('The specified snippet is not valid UTF-8; send it exactly as it appears in the file.');
    }

    /** @return string something already sits where a folder would go */
    public static function crossStorage(): string {
        return Translator::t('Moving across different storages is not supported in this version; move within the same file area.');
    }

    /**
     * @param int $conflicts how many items clash with what is already there
     * @param int $denied how many items Nextcloud will not let the user touch
     * @return string a batch whose plan still has blocked items
     */
    public static function batchNotOk(int $conflicts, int $denied): string {
        return Translator::t('The plan has %s conflict(s) and %s item(s) without permission; call it again without confirm, fix the list and try again. Nothing was moved.', [(string)$conflicts, (string)$denied]);
    }

    /** @return string a folder moved into itself or into one of its own subfolders */
    public static function folderIntoItself(): string {
        return Translator::t('Cannot move a folder into itself.');
    }

    /**
     * @param int $limit ceiling in nodes
     * @return string a copy whose source is larger than the node ceiling
     */
    public static function copyTooManyNodes(int $limit): string {
        return Translator::t('The copy would exceed %s items; nothing was copied. Choose a smaller folder.', [(string)$limit]);
    }

    /**
     * @param int $limit ceiling in bytes
     * @return string a copy whose source is larger than the byte ceiling
     */
    public static function copyTooLarge(int $limit): string {
        return Translator::t('The copy would exceed %s bytes; nothing was copied. Choose a smaller folder.', [(string)$limit]);
    }

    /** @return string something already sits where a folder would go */
    public static function destinationExists(): string {
        return Translator::t('A file or folder already exists at this destination.');
    }

    /**
     * @param int $occurrences how many times the snippet occurs
     * @return string the snippet given to files_replace is ambiguous
     */
    public static function snippetAmbiguous(int $occurrences): string {
        return Translator::t('The specified snippet appears %s times in the file; provide a unique snippet.', [(string)$occurrences]);
    }

    /** @return string the file could not be opened */
    public static function openFailed(): string {
        return Translator::t('Could not open file.');
    }

    /** @return string the file could not be read */
    public static function readFailed(): string {
        return Translator::t('Could not read file.');
    }

    /** @return string an unsupported format for text extraction */
    public static function unsupportedFormat(): string {
        return Translator::t('Unsupported file format for text extraction.');
    }

    /**
     * @param int $limit read limit in bytes
     * @return string a file over the read limit
     */
    public static function readTooLarge(int $limit): string {
        return Translator::t('File exceeds the read limit of %s bytes.', [(string)$limit]);
    }

    /**
     * @param int $limit binary read limit in bytes
     * @return string a binary resource over the read limit
     */
    public static function binaryResourceTooLarge(int $limit): string {
        return Translator::t('Resource exceeds the binary read limit of %s bytes.', [(string)$limit]);
    }

    /**
     * @param string $name file name
     * @return string a corrupt document whose text could not be extracted
     */
    public static function notExtracted(string $name): string {
        return Translator::t('[could not extract text from %s]', [$name]);
    }

    /** @return string notice for a PDF or image without text when Workflow OCR is not available */
    public static function noTextOcrInactive(): string {
        return Translator::t('This file has no text layer. Ask the administrator to install the Workflow OCR app, or view the page as an image with the image tools.');
    }

    /** @return string notice for a PDF or image without text when Workflow OCR is active */
    public static function noTextOcrActive(): string {
        return Translator::t('This file still has no text. Workflow OCR processes files in the background, following the rule the administrator configured.');
    }

    /** @return string appends to text cut by files_read */
    public static function textTruncated(): string {
        return Translator::t('[content truncated]');
    }

    // ---------------------------------------------------------------- versions

    /** @return string versioning is required and not active */
    public static function versionsOff(): string {
        return Translator::t('File versioning (files_versions) is not active on this account.');
    }

    /**
     * @param string $requested version identifier asked for
     * @return string no version with that identifier
     */
    public static function versionMissing(string $requested): string {
        return Translator::t('Version not found for this file: %s.', [$requested]);
    }

    /** @return string the version could not be read */
    public static function versionUnreadable(): string {
        return Translator::t('Could not read content of this version.');
    }

    /** @return string the rollback did not report success */
    public static function versionRestoreFailed(): string {
        return Translator::t('Could not restore this version.');
    }

    // ---------------------------------------------------------------- checkout

    /**
     * @param string $path normalized path refused for checkout
     * @return string a folder or a non-text file
     */
    public static function checkoutNotEditable(string $path): string {
        return Translator::t('Cannot prepare editing for this file.');
    }

    /** @return string app service, eligibility or connection is off right now */
    public static function checkoutRevoked(): string {
        return Translator::t('MCP connection is disabled or access to this folder is no longer granted.');
    }

    /** @return string the checkout token is unknown */
    public static function tokenInvalid(): string {
        return Translator::t('Invalid edit link.');
    }

    /** @return string the checkout token expired or was already used */
    public static function tokenSpent(): string {
        return Translator::t('This edit link has expired or has already been used. Please checkout again.');
    }

    /** @return string the checkout token is of the wrong kind for this route */
    public static function tokenWrongKind(): string {
        return Translator::t('Invalid edit link.');
    }

    /**
     * @param int $limit effective limit in bytes
     * @return string an upload over the limit
     */
    public static function uploadTooLarge(int $limit): string {
        return Translator::t('Content exceeds the upload limit of %s bytes.', [(string)$limit]);
    }

    /**
     * The ETag no longer matches. The token is already spent, so the agent cannot retry it: it has to read
     * the file again and make a new checkout, which is what this message asks for.
     *
     * @return string the client-safe reason for a 409 on the upload
     */
    public static function uploadConflict(): string {
        return Translator::t('The file changed after checkout; nothing was written. Re-read the file and run a new files_checkout before uploading again.');
    }

    /** @return string a multipart body, the shape `curl -F` produces instead of raw bytes */
    public static function uploadMultipart(): string {
        return Translator::t('Send file bytes as raw body (curl -T), not as multipart form.');
    }

    /** @return string a body the upload route does not interpret */
    public static function uploadWrongType(): string {
        return Translator::t('Send body as application/octet-stream with file bytes.');
    }

    /** @return string a body that carried nothing at all */
    public static function uploadEmpty(): string {
        return Translator::t('The uploaded body is empty; nothing was written.');
    }

    /**
     * @param string $backup path of the verified copy of the original, '' when the failure came before it
     * @return string a failed write, naming where the original still is
     */
    public static function uploadFailed(string $backup): string {
        return $backup === ''
            ? Translator::t('Failed to write file.')
            : Translator::t('Failed to write file; original preserved in %s.', [$backup]);
    }

    // ---------------------------------------------------------------- new files

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

    /** @return string description of files_create, read by the model and therefore fixed English */
    public static function createTool(): string {
        return 'Create a NEW small text file with its content inline: ' . implode(', ', array_map(
            static fn (string $extension): string => '.' . $extension, FileCreation::TEXT_EXTENSIONS))
            . ', up to 1 MB of UTF-8. Never overwrites a file with content: an existing name is refused (use files_edit or files_checkout '
            . 'to change it). For any other type or a larger file use files_upload. The folder must exist '
            . '(files_mkdir creates it); outside your personal folder ask the user and retry with confirm_shared.';
    }

    /** @return string description of the path of files_upload and files_create */
    public static function newFilePath(): string {
        return 'Full path of the new file, including its name, e.g. /Reports/Report.docx';
    }

    /** @return string description of the size argument of files_upload */
    public static function uploadSize(): string {
        return 'Size of the local file in bytes, optional: a size over the upload limit is refused before any link is issued';
    }

    /** @return string description of the content argument of files_create */
    public static function createContent(): string {
        return 'Complete text of the new file, up to 1 MB of UTF-8';
    }

    /** @return string the plan of files_upload */
    public static function planUpload(): string {
        return Translator::t('Nothing was changed. After your approval a single-use upload link is issued that creates this new file; the file only appears once the bytes are sent to it.');
    }

    /** @return string the plan of files_create */
    public static function planCreate(): string {
        return Translator::t('Nothing was changed. After your approval the new text file below is created.');
    }

    /** @return string the consequence of a new file, shared by files_upload and files_create */
    public static function planNewFileConsequence(): string {
        return Translator::t('Nothing is overwritten: if a file with this name already exists, with content, nothing is written.');
    }

    /** @return string the confirmed files_upload, telling the agent what to do with the link */
    public static function uploadIssued(): string {
        return Translator::t('Send the file with the curl command below before the link expires. The link works once: if the upload is refused before it is used, fix the request and send it again; otherwise call files_upload again.');
    }

    /**
     * @param int $declared size files_upload declared for the file
     * @return string an empty body for a link that expected bytes: the client failed to build the file
     */
    public static function uploadEmptyDeclared(int $declared): string {
        return Translator::t('The uploaded body is empty, but files_upload declared %s bytes; nothing was written and the link still works.', [(string)$declared]);
    }

    /** @return string the create link of files_upload expired or was already used */
    public static function createTokenSpent(): string {
        return Translator::t('This upload link has expired or has already been used. Call files_upload again.');
    }

    /** @return string a new file whose name is already taken */
    public static function fileExists(): string {
        return Translator::t('A file with this name already exists; use files_checkout to replace it or choose another name.');
    }

    /**
     * The folder of a new file is missing or hidden. Both answer the same, starting with the generic not-found,
     * so a hidden folder cannot be told apart from one that does not exist.
     *
     * @return string the refusal, with the way out for a folder that really is missing
     */
    public static function createFolderMissing(): string {
        return CommonMessages::notFound() . ' ' . Translator::t('If the destination folder does not exist yet, create it first with files_mkdir.');
    }

    /**
     * @param int $limit maximum accepted size in bytes
     * @return string inline content over the files_create limit
     */
    public static function createTooLarge(int $limit): string {
        return Translator::t('Content exceeds the limit of %s bytes for files_create; use files_upload for a larger file.', [(string)$limit]);
    }

    /** @return string the write of a new file failed after the empty file may have appeared */
    public static function createFailed(): string {
        return Translator::t('Failed to write file; an empty file with this name may have been left in place.');
    }

    /** @return string the rule of a path that names no file Nextcloud accepts */
    public static function newFileNameRule(): string {
        return Translator::t('must end in a file name Nextcloud accepts: no reserved name, forbidden character or extension, at most 250 bytes');
    }

    /** @return string the rule of a path whose extension files_create does not write */
    public static function createExtensionRule(): string {
        return Translator::t('files_create only writes text files (%s); use files_upload for any other type', [implode(', ', array_map(
            static fn (string $extension): string => '.' . $extension, FileCreation::TEXT_EXTENSIONS))]);
    }

    // ---------------------------------------------------------------- sharing

    /** @return string description of files_list_shares */
    public static function listSharesTool(): string {
        return 'List the shares you created: with a path, the shares of that file or folder (which must be yours); '
            . 'without one, every share you created, ' . \OCA\Mcp\Tools\Files\Sharing\ShareAccess::PAGE_SIZE . ' per page '
            . 'with offset. Each item has shareId, type (user, group, link or room), with (id and display name), '
            . 'permission (view, edit or custom), reshare, expires, hasPassword, url (links only), removable and path. '
            . 'Passwords are never returned. A room share is a Talk attachment: it is listed with removable: false '
            . 'and is removed in Talk.';
    }

    /** @return string description of the path parameter of files_list_shares */
    public static function listSharesPath(): string {
        return 'File or folder of yours whose shares to list, e.g. /Documents/report.pdf. Omit to list every share you created.';
    }

    /** @return string description of the offset parameter of files_list_shares */
    public static function listSharesOffset(): string {
        return 'Shares to skip when listing without path; use the nextOffset of the previous page.';
    }

    /** @return string name shown for the recipient of a public link */
    public static function publicLink(): string {
        return Translator::t('Public link');
    }

    /** @return string a share asked for a node the user does not own */
    public static function shareNotOwner(): string {
        return Translator::t('Only files and folders you own can be shared here; this one belongs to someone else.');
    }

    /** @return string a share asked for the root of the user folder */
    public static function shareRootRefused(): string {
        return Translator::t('Your whole folder cannot be shared; choose a file or a folder inside it.');
    }

    /** @return string the administrator did not grant the sharing operation this type needs */
    public static function shareNotGranted(): string {
        return Translator::t('Your administrator has not allowed the AI to manage this kind of share.');
    }

    /** @return string a share type the tools do not manage, such as a Talk attachment */
    public static function shareTypeUnsupported(): string {
        return Translator::t('This kind of share is not managed here; a Talk attachment is removed in Talk.');
    }

    /** @return string description of files_share */
    public static function shareTool(): string {
        return 'Share a file or folder of yours with a person or a group, or change how it is already shared with them. '
            . 'Find the account or group id with users_search first (include_groups: true for groups); never guess it. '
            . 'Sharing again with the same recipient changes that share instead of creating another one. permission is '
            . 'view (open and download) or edit (change; on a folder also add and delete inside it); a share never lets '
            . 'the recipient share it on. expires is the last day (YYYY-MM-DD, in the user\'s timezone, tomorrow or later); '
            . 'omit it to keep the current one, or for a new share to get the administrator\'s default. Without confirm: '
            . 'true it returns the plan and changes nothing. Nextcloud notifies the recipient. Needs the "share" permission. '
            . 'with: "link" creates or changes the public link of the file (one per file, view only, anyone with it can '
            . 'open it) and needs the "link" permission; its password is generated by the server when you pass '
            . 'password: true or the administrator requires one, and is returned once in the confirmed result only.';
    }

    /** @return string description of the with parameter of files_share */
    public static function shareWithParam(): string {
        return 'Recipient: user:<uid> for a person, group:<gid> for a group, or link for a public link.';
    }

    /** @return string description of the permission parameter of files_share */
    public static function sharePermissionParam(): string {
        return 'view to open and download; edit to change too (on a folder: add, change and delete inside it).';
    }

    /** @return string description of the expires parameter of files_share */
    public static function shareExpiresParam(): string {
        return 'Last day of access, YYYY-MM-DD in the user\'s timezone, tomorrow or later.';
    }

    /** @return string description of the note parameter of files_share */
    public static function shareNoteParam(): string {
        return 'Note shown to the recipient with the share; an empty string removes the current note.';
    }

    /** @return string description of files_unshare */
    public static function unshareTool(): string {
        return 'Stop sharing a file or folder of yours: removes one share you created, so that person, group or public link '
            . 'loses access. Name the share with the shareId from files_list_shares, or with path plus with (user:<uid>, '
            . 'group:<gid> or link). Nothing is deleted: the file or folder and its content stay as they are. Only your own '
            . 'shares of your own files can be removed; a Talk attachment is removed in Talk. Without confirm: true it '
            . 'returns the plan and changes nothing. Needs the "share" permission; removing a public link needs "link".';
    }

    /** @return string description of the shareId parameter of files_unshare */
    public static function unshareIdParam(): string {
        return 'The shareId of the share to remove, exactly as files_list_shares returns it (for example ocinternal:123). '
            . 'Use it alone, or use path with with instead.';
    }

    /** @return string description of the path parameter of files_unshare */
    public static function unsharePathParam(): string {
        return 'File or folder of yours, e.g. /Documents/report.pdf. Use it together with with.';
    }

    /** @return string description of the with parameter of files_unshare */
    public static function unshareWithParam(): string {
        return 'Whose share to remove from path: user:<uid>, group:<gid> or link. Use it together with path.';
    }

    /** @return string the core refused to remove a share */
    public static function unshareRefused(): string {
        return Translator::t('Nextcloud could not remove this share.');
    }

    /** @return string advice for the model when the share already is what was asked */
    public static function planShareNothing(): string {
        return Translator::t('Nothing was changed. The share already is exactly like this, so a confirmed call changes nothing either.');
    }

    /** @return string a share whose recipient is the user */
    public static function shareWithSelf(): string {
        return Translator::t('You already have this item; choose another person to share it with.');
    }

    /** @return string the password policy refused every password the server generated for a link */
    public static function linkPasswordRefused(): string {
        return Translator::t('The server could not generate a password that meets the password policy, so no link was created. Ask your administrator to check the password policy.');
    }

    /** @return string public links turned off by the administrator, for everyone or for the user's groups */
    public static function shareLinksDisabled(): string {
        return Translator::t('Your administrator turned public links off for your account.');
    }

    /** @return string the warning every plan of a public link carries */
    public static function linkPublicWarning(): string {
        return Translator::t('Anyone who has the link can open this, without signing in.');
    }

    /** @return string what the result of a link says next to the password it generated */
    public static function linkPasswordShownOnce(): string {
        return Translator::t('Save this password now and send it separately from the link: it will not be shown again. To change it later, share the link again with password: true.');
    }

    /** @return string description of the password parameter of files_share */
    public static function sharePasswordParam(): string {
        return 'Public link only: true makes the server generate a new password, which replaces the current one and is '
            . 'shown once in the result. Never choose a password yourself.';
    }

    /** @return string sharing turned off on the server */
    public static function shareDisabled(): string {
        return Translator::t('Sharing is turned off on this server.');
    }

    /** @return string sharing turned off for this account */
    public static function shareDisabledForYou(): string {
        return Translator::t('Your administrator turned sharing off for your account.');
    }

    /** @return string sharing with groups turned off */
    public static function shareGroupsDisabled(): string {
        return Translator::t('Sharing with groups is turned off on this server.');
    }

    /** @return string the members-only rule refuses a person outside the user's groups */
    public static function shareOnlyGroupMembers(): string {
        return Translator::t('On this server you can only share with people who are in one of your groups.');
    }

    /** @return string the members-only rule refuses a group the user is not in */
    public static function shareOnlyOwnGroups(): string {
        return Translator::t('On this server you can only share with groups you are a member of.');
    }

    /** @return string a node the user may not share, such as one without the share permission */
    public static function shareNodeNotShareable(): string {
        return Translator::t('Nextcloud does not allow sharing this item.');
    }

    /** @return string a level above what the user may do with the node */
    public static function shareAboveOwnPermissions(): string {
        return Translator::t('You cannot give more access than you have on this item; share it with view instead.');
    }

    /** @return string the core says the recipient already has access through another share */
    public static function shareAlreadyHasAccess(): string {
        return Translator::t('This recipient already has access to this item through another share.');
    }

    /** @return string the warning of a plan a confirmed files_share or files_unshare answers with, because the share changed */
    public static function sharePlanChanged(): string {
        return Translator::t('The share changed since the plan; check it again.');
    }

    /** @return string any other refusal of the core, without its message */
    public static function shareRefused(): string {
        return Translator::t('Nextcloud refused this share because of a sharing rule of this server.');
    }

    /**
     * @param string $group display name of the group
     * @return string warning of a group share
     */
    public static function shareGroupWarning(string $group): string {
        return Translator::t('Every member of %s gets access, including people who join the group later.', [$group]);
    }

    /**
     * @param string $recipient display name of the person or group
     * @return string warning of a folder shared with edit
     */
    public static function shareFolderEditWarning(string $recipient): string {
        return Translator::t('With edit on a folder, %s can also delete files inside it.', [$recipient]);
    }
}
