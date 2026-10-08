<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\L10n\Translator;

/**
 * The MCP prompts this server offers, in the shape both protocol eras expect.
 *
 * A prompt is a workflow the client can pull on demand. `edit_nextcloud_file_locally` teaches the
 * checkout → download → edit locally → upload round trip to an agent that has a disk and a shell, and
 * names the in-context tools to fall back on when it has none. `create_nextcloud_file` teaches files_upload
 * and files_create, which make a NEW file instead of replacing one. Each prompt follows the grant its tools
 * need, and the edit prompt only names the creation tools to someone who may use them.
 */
final class PromptCatalog {
    /** Name of the local editing prompt, as the client sees it. */
    public const EDIT_LOCALLY = 'edit_nextcloud_file_locally';
    /** Name of the new file prompt, as the client sees it. */
    public const CREATE_FILE = 'create_nextcloud_file';
    /** Module whose grants decide which prompts are offered. */
    private const MODULE = 'files';
    /** Grant of the checkout workflow; without it the tools it names are not listed either. */
    private const OPERATION = 'edit';
    /** Grant of files_upload and files_create. */
    private const OPERATION_CREATE = 'create';

    /**
     * @return list<array{name:string, title:string, description:string, arguments:list<array{name:string, description:string, required:bool}>}>
     *   the prompts the caller may use now
     */
    public function list(GrantPolicy $policy, string $userId): array {
        $prompts = [];
        if ($policy->granted($userId, self::MODULE, self::OPERATION)) {
            $prompts[] = [
                'name' => self::EDIT_LOCALLY,
                'title' => Translator::t('Edit a Nextcloud file locally'),
                'description' => Translator::t('Teaches how to download a Nextcloud file, edit it with local tools, and send it back without passing the content through the model.'),
                'arguments' => [],
            ];
        }
        if ($policy->granted($userId, self::MODULE, self::OPERATION_CREATE)) {
            $prompts[] = [
                'name' => self::CREATE_FILE,
                'title' => Translator::t('Create a new Nextcloud file'),
                'description' => Translator::t('Teaches how to send a file generated locally to Nextcloud as a new file, or to create a small text file inline.'),
                'arguments' => [],
            ];
        }
        return $prompts;
    }

    /**
     * @param string $name prompt name asked for
     * @param GrantPolicy $policy grants of the caller
     * @param string $userId authenticated user
     * @return array{description:string, messages:list<array{role:string, content:array{type:string, text:string}}>}|null
     *   the rendered prompt, or null when the name is unknown or not granted
     */
    public function get(string $name, GrantPolicy $policy, string $userId): ?array {
        if (!in_array($name, array_column($this->list($policy, $userId), 'name'), true)) {
            return null;
        }
        $create = $policy->granted($userId, self::MODULE, self::OPERATION_CREATE);
        return $name === self::EDIT_LOCALLY
            ? self::prompt(Translator::t('Local editing workflow for a Nextcloud file.'), self::editText($create))
            : self::prompt(Translator::t('Workflow for creating a new Nextcloud file.'), self::createText());
    }

    /**
     * @param string $description translated description of the prompt
     * @param string $text the workflow, in fixed English
     * @return array{description:string, messages:list<array{role:string, content:array{type:string, text:string}}>}
     */
    private static function prompt(string $description, string $text): array {
        return ['description' => $description, 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
    }

    /**
     * @param bool $create whether the caller also holds files.create, and so may be told about the creation tools
     * @return string the checkout workflow handed to the model, in fixed English
     */
    private static function editText(bool $create): string {
        $lines = [
            'To edit a Nextcloud file without passing its content through the model, use the checkout workflow:',
            '',
            '1. Call files_checkout with the path. It returns download_url, upload_url, the current ETag, and expires_at.',
            '2. Download the file using your shell: curl -sS -o /tmp/file "$DOWNLOAD_URL".',
            '   The link is valid for a few minutes and single-use: if it fails, run files_checkout again.',
            '3. Edit /tmp/file with your local tools. The user sees the diff; do not write anything outside the file.',
            '4. Return the file with curl -sS -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL".',
            '   The server validates the token and ETag, creates a backup in "/MCP backups", saves the file, and returns the new ETag.',
            '   If the file changed in the meantime, the response is a conflict (409) and nothing was written: re-read and repeat.',
            '',
            'If you do not have a shell to download and upload the file, do not use checkout: apply the change with',
            'files_replace (replaces a unique snippet and returns the diff) or with files_edit (full content).',
            'files_edit and files_replace return the unified diff of before and after, truncated at 20,000 characters.',
            '',
            'Shared scope: any response with "requiresConfirmation": true means the file is outside your personal',
            'folder (shared, group folder, or external storage). In that case, DO NOT repeat the call immediately:',
            'show the received message to the user and ask if they wish to proceed. Then, confirm with confirm_shared: true.',
            'Without this confirmation, nothing is written.',
            '',
            'Upload note: checkout links are single-use and already consumed once the response arrives.',
            'If the upload returns a confirmation prompt or a conflict, run files_checkout again — passing',
            'confirm_shared: true if the user has already confirmed — instead of retrying the same link.',
            '',
            'To revert, use files_versions_list and files_version_restore; restoring also creates a backup beforehand.',
        ];
        if ($create) {
            $lines[] = '';
            $lines[] = 'A NEW file is not a checkout: files_checkout only replaces a file that already exists.';
            $lines = array_merge($lines, self::createLines());
        }
        return implode("\n", $lines);
    }

    /** @return string the new file workflow handed to the model, in fixed English */
    private static function createText(): string {
        return implode("\n", array_merge([
            'To create a NEW file in Nextcloud without passing its content through the model:',
            '',
        ], self::createLines(), [
            '',
            'Shared scope: a response with "requiresConfirmation": true means the folder is outside your personal folder.',
            'Show the message to the user, ask if they wish to proceed, and only then repeat with confirm_shared: true.',
            'To replace a file that already exists, files_checkout (download, edit, upload) is the tool, when it is granted.',
        ]));
    }

    /** @return list<string> how files_upload and files_create are used, shared by both prompts */
    private static function createLines(): array {
        return [
            'To create a new file you generated locally (docx, xlsx, pdf, image, any type), call files_upload with the full',
            'path, show the plan to the user and confirm it; it returns uploadUrl, a single-use link valid for a few minutes.',
            'Send the file with curl -sS -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL".',
            'Pass size (bytes of the local file) so an empty upload by mistake is refused and the link still works.',
            'For a small text file (.md, .txt, .csv, .json, .html, .xml, .yaml) use files_create with the content inline.',
            'These tools never overwrite a file with content, and the folder must already exist (create it with files_mkdir).',
        ];
    }
}
