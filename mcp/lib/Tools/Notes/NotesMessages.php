<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of the Notes module.
 *
 * Tool and parameter descriptions are read by the model, not by the person, so they are fixed English
 * constants. What the user can see (the refusals) is a static method that translates an English source
 * text through {@see Translator}, in the language of the authenticated user.
 */
final class NotesMessages {
    /* Tool descriptions, exposed to MCP clients in tools/list. */
    public const TOOL_LIST_DESCRIPTION = 'Lists the notes (Notes app) of the user.';
    public const TOOL_READ_DESCRIPTION = 'Reads the content of a note by id.';
    public const TOOL_CREATE_DESCRIPTION = 'Creates a note; never overwrites an existing one.';
    public const TOOL_EDIT_DESCRIPTION = 'Changes the content and/or the title of a note.';
    public const TOOL_MOVE_DESCRIPTION = 'Moves a note to another category.';
    public const TOOL_DELETE_DESCRIPTION = 'Deletes a note to the Nextcloud trash bin. Requires confirm=true.';

    /* Parameter descriptions. */
    public const PARAM_ID = 'Id of the note.';
    public const PARAM_ETAG = 'ETag read before; when it differs, nothing is changed.';
    public const PARAM_TITLE = 'Title (file name).';
    public const PARAM_CONTENT = 'Content in Markdown.';
    public const PARAM_CATEGORY = 'Category (subfolder); empty for the root.';
    public const PARAM_NEW_CONTENT = 'New complete content.';
    public const PARAM_NEW_TITLE = 'New title.';
    public const PARAM_CATEGORY_TARGET = 'Destination category; empty for the root.';
    public const PARAM_CONFIRM = 'Must be true to confirm the deletion.';

    private function __construct() {
    }

    /**
     * Note larger than MAX_BYTES when it is being read.
     * @param int $max limit in bytes
     * @return string The message in the language of the current user.
     */
    public static function noteTooLargeForReading(int $max): string {
        return Translator::t('Note exceeds the read limit of %d bytes.', [$max]);
    }

    /**
     * Note larger than MAX_BYTES when it is being written.
     * @param int $max limit in bytes
     * @return string The message in the language of the current user.
     */
    public static function noteTooLarge(int $max): string {
        return Translator::t('Note exceeds the limit of %d bytes.', [$max]);
    }

    /**
     * Renaming a note onto a title already used in the same category.
     * @return string The message in the language of the current user.
     */
    public static function titleExistsInCategory(): string {
        return Translator::t('A note with this title already exists in this category.');
    }

    /**
     * Moving a note onto a title already used in the target category.
     * @return string The message in the language of the current user.
     */
    public static function titleExistsInTargetCategory(): string {
        return Translator::t('A note with this title already exists in the destination category.');
    }

    /**
     * The title already has the maximum number of copies with a " (n)" suffix.
     * @return string The message in the language of the current user.
     */
    public static function tooManyNotesWithSameTitle(): string {
        return Translator::t('There are already too many notes with this title.');
    }

    /**
     * Deletion the trash bin would not take back, so it is refused.
     * @return string The message in the language of the current user.
     */
    public static function notRecoverable(): string {
        return Translator::t('Deletion blocked: the trash bin (files_trashbin) is not active for this note, so it would not be recoverable.');
    }
}
