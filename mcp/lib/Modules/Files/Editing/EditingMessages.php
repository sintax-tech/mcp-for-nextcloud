<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\BackupLocation;

final class EditingMessages {
    /**
     * @param int $limit maximum accepted size in bytes
     * @return string a content over the edit limit
     */
    public static function editTooLarge(int $limit): string {
        return Translator::t('Content exceeds the edit limit of %s bytes.', [(string)$limit]);
    }

    /**
     * @return string the plan of files_edit
     */
    public static function planEdit(): string {
        return Translator::t('Nothing was changed. After your approval the file is replaced by the content below and a copy of the current content is kept in the backup folder.');
    }

    /** @return string a non-text file */
    public static function notText(): string {
        return Translator::t('Only text files can be edited via MCP.');
    }

    /** @return string an edit inside the backup folder */
    public static function backupPath(): string {
        return Translator::t('Files in \'/%s\' cannot be edited via MCP.', [BackupLocation::FOLDER]);
    }

    /** @return string versioning is off for the user */
    public static function versioningOff(): string {
        return Translator::t('Editing blocked: file versioning (files_versions) is not active.');
    }

    /**
     * @return string what the backup taken by a write gives back
     */
    public static function planBackupConsequence(): string {
        return Translator::t('The previous content stays available in the backup folder. Nextcloud may also keep versions of the file, according to its versioning policy.');
    }

    /** @return string the snippet given to files_replace is not text we can locate in the file */
    public static function snippetNotUtf8(): string {
        return Translator::t('The specified snippet is not valid UTF-8; send it exactly as it appears in the file.');
    }

    /** @return string the snippet given to files_replace does not occur */
    public static function snippetMissing(): string {
        return Translator::t('The specified snippet does not appear in the file.');
    }

    /**
     * @param int $occurrences how many times the snippet occurs
     * @return string the snippet given to files_replace is ambiguous
     */
    public static function snippetAmbiguous(int $occurrences): string {
        return Translator::t('The specified snippet appears %s times in the file; provide a unique snippet.', [(string)$occurrences]);
    }

    /**
     * @return string the plan of files_replace
     */
    public static function planReplace(): string {
        return Translator::t('Nothing was changed. After your approval the snippet below is replaced and a copy of the current content is kept in the backup folder.');
    }

    /**
     * @param string $backup user-relative path of the backup taken before the refused write
     * @return string closing sentence of a lock refusal that came after the backup
     */
    public static function originalKept(string $backup): string {
        return Translator::t('The original is kept in %s.', [$backup]);
    }

    /**
     * @param string $backup path of the verified copy of the original
     * @return string a failed write, naming where the original still is
     */
    public static function writeFailed(string $backup): string {
        return Translator::t('Failed to write file; original preserved in %s.', [$backup]);
    }

    /** @return string description of files_edit */
    public static function editTool(): string {
        return 'Replace the content of an existing text file and return the diff. Before writing, requires '
            . 'active versioning and creates a copy in "/MCP backups". If the file is outside your personal '
            . 'folder, only write after asking the user and retrying the call with confirm_shared. '
            . 'Never creates, moves, or deletes files.';
    }

    /** @return string description of the content parameter of files_edit */
    public static function content(): string {
        return 'Complete new content (UTF-8)';
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

    /** @return string description of files_replace */
    public static function replaceTool(): string {
        return 'Replace a unique snippet of an existing text file and return the diff. The specified snippet '
            . 'must appear exactly once. Before writing, requires active versioning and creates a copy '
            . 'in "/MCP backups". If the file is outside your personal folder, only write after '
            . 'asking the user and retrying the call with confirm_shared.';
    }

    /** @return string description of the old parameter of files_replace */
    public static function oldSnippet(): string {
        return 'Snippet to replace; must appear exactly once in the file';
    }

    /** @return string description of the new parameter of files_replace */
    public static function newSnippet(): string {
        return 'Replacement text for the specified snippet';
    }
}
