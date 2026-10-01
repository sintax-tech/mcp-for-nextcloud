<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use OCA\Mcp\L10n\Translator;

/**
 * How tools are shown to the model and to the person reading the conversation.
 *
 * Clients such as claude.ai display the Tool `title` (and the `annotations.title`) instead of the
 * technical name, both in the live tool-use card and in the answer text; keeping the technical name
 * out of the visible layer is what makes the transcript readable. The map is central so a module can
 * add its tools without touching this class's callers.
 */
final class ToolPresentation {
    /** Guidance sent in `initialize` and in `server/discover`; read by the model, so it is fixed English. */
    public const INSTRUCTIONS = "Reply in the user's language. When you mention a tool to the user, use its title "
        . "(for example \"Search files\"), never its technical name. Show file paths and names in a readable way.";

    /** Operations that only read; every other operation may change data. */
    private const READ_OPERATION = 'read';
    /**
     * Operations a user cannot take back from the client: the ones that replace or remove what exists, and the
     * ones that publish a message under their own name in a room full of other people. Replying, sharing a file
     * and quoting an attachment only add rows nobody deletes for them, but once sent the user has to ask somebody
     * else to remove them, which is exactly the confirmation a client offers on a destructive tool.
     */
    private const DESTRUCTIVE_OPERATIONS = ['delete', 'restore', 'transfer', 'move', 'reply', 'attach', 'quote'];
    /**
     * Tools whose operation is harmless elsewhere but not for them. Creating a note adds a file only its owner sees;
     * creating a Talk group invites other people, and the client cannot take an invitation back.
     */
    private const DESTRUCTIVE_TOOLS = ['talk_create_group'];

    private function __construct() {
    }

    /**
     * Human-readable title for a tool in the language of the current user, falling back to the humanized technical name.
     *
     * @param string $name technical tool name
     */
    public static function title(string $name): string {
        // One literal per call: the translation tool extracts the source texts from here.
        return match ($name) {
            // Diagnostics
            'mcp_status' => Translator::t('Check MCP status'),
            // Files
            'files_list' => Translator::t('List files'),
            'files_search' => Translator::t('Search files'),
            'files_read' => Translator::t('Read file'),
            'files_edit' => Translator::t('Edit file'),
            'files_tree' => Translator::t('View folder tree'),
            'files_mkdir' => Translator::t('Create folder'),
            'files_copy' => Translator::t('Copy file'),
            'files_move' => Translator::t('Move file'),
            'files_move_batch' => Translator::t('Move files in bulk'),
            'files_undo_batch' => Translator::t('Undo bulk operation'),
            'files_replace' => Translator::t('Replace part of a file'),
            'files_checkout' => Translator::t('Download file for local editing'),
            'files_versions_list' => Translator::t('List file versions'),
            'files_version_read' => Translator::t('Read file version'),
            'files_version_restore' => Translator::t('Restore file version'),
            // Notes
            'notes_list' => Translator::t('List notes'),
            'notes_read' => Translator::t('Read note'),
            'notes_create' => Translator::t('Create note'),
            'notes_edit' => Translator::t('Edit note'),
            'notes_move' => Translator::t('Move note'),
            'notes_delete' => Translator::t('Delete note'),
            // Calendar
            'calendar_list_calendars' => Translator::t('List calendars'),
            'calendar_list_events' => Translator::t('List events'),
            'calendar_create_event' => Translator::t('Create event'),
            'calendar_update_event' => Translator::t('Edit event'),
            'calendar_move_event' => Translator::t('Move event to another calendar'),
            'calendar_transfer_event' => Translator::t('Transfer event to another person\'s calendar'),
            'calendar_delete_event' => Translator::t('Delete event'),
            // Deck
            'deck_list_boards' => Translator::t('List Deck boards'),
            'deck_list_stacks' => Translator::t('List Deck lists'),
            'deck_list_cards' => Translator::t('List Deck cards'),
            'deck_read_card' => Translator::t('Read Deck card'),
            'deck_create_card' => Translator::t('Create card in Deck'),
            'deck_edit_card' => Translator::t('Edit Deck card'),
            'deck_move_card' => Translator::t('Move card in Deck'),
            'deck_delete_card' => Translator::t('Delete Deck card'),
            'deck_followup_cards' => Translator::t('Follow up on Deck tasks'),
            // Talk
            'talk_list_conversations' => Translator::t('List Talk conversations'),
            'talk_read_messages' => Translator::t('Read Talk messages'),
            'talk_reply' => Translator::t('Reply in Talk'),
            'talk_attach_file' => Translator::t('Attach file in Talk'),
            'talk_quote_file' => Translator::t('Quote file in Talk'),
            'talk_message_user' => Translator::t('Direct message in Talk'),
            'talk_send_batch' => Translator::t('Send batch in Talk'),
            'talk_create_group' => Translator::t('Create group in Talk'),
            default => self::humanized($name),
        };
    }

    /**
     * MCP tool annotations derived from the grant operation the module declares.
     *
     * `openWorldHint` is always false: every tool works inside the caller's own Nextcloud.
     *
     * @param string $name technical tool name
     * @param string $operation grant operation of the tool definition (read, create, edit, delete, move, transfer, restore)
     * @param bool $destructiveHint a module may strengthen the default confirmation hint
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public static function annotations(string $name, string $operation, bool $destructiveHint = false): array {
        $readOnly = $operation === self::READ_OPERATION;
        return [
            'title' => self::title($name),
            'readOnlyHint' => $readOnly,
            'destructiveHint' => $destructiveHint || in_array($operation, self::DESTRUCTIVE_OPERATIONS, true)
                || in_array($name, self::DESTRUCTIVE_TOOLS, true),
            'idempotentHint' => $readOnly,
            'openWorldHint' => false,
        ];
    }

    /**
     * Best-effort display name for a tool that has no entry yet: "notes_list" becomes "List".
     * Public so the contract test can tell a mapped title from a fallback one.
     *
     * @param string $name technical tool name
     */
    public static function humanized(string $name): string {
        $parts = array_slice(explode('_', $name), 1);
        $words = implode(' ', $parts === [] ? [$name] : $parts);
        return ucfirst($words);
    }
}