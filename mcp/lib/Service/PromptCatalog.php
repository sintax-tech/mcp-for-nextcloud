<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\L10n\Translator;

/**
 * The MCP prompts this server offers, in the shape both protocol eras expect.
 *
 * A prompt is a workflow the client can pull on demand. `edit_nextcloud_file_locally` teaches the
 * checkout → download → edit locally → upload round trip to an agent that has a disk and a shell, names the
 * in-context tools to fall back on when it has none, and sends a file generated locally to files_upload or
 * files_create instead of a checkout, which only replaces a file that already exists.
 */
final class PromptCatalog {
    /** Name of the only prompt, as the client sees it. */
    public const EDIT_LOCALLY = 'edit_nextcloud_file_locally';
    /** Grant the prompt's workflow needs; without it the tools it names are not listed either. */
    private const MODULE = 'files';
    private const OPERATION = 'edit';

    /**
     * @return list<array{name:string, title:string, description:string, arguments:list<array{name:string, description:string, required:bool}>}>
     *   the prompts the caller may use now
     */
    public function list(GrantPolicy $policy, string $userId): array {
        if (!$policy->granted($userId, self::MODULE, self::OPERATION)) {
            return [];
        }
        return [[
            'name' => self::EDIT_LOCALLY,
            'title' => Translator::t('Edit a Nextcloud file locally'),
            'description' => Translator::t('Teaches how to download a Nextcloud file, edit it with local tools, and send it back without passing the content through the model.'),
            'arguments' => [],
        ]];
    }

    /**
     * @param string $name prompt name asked for
     * @param GrantPolicy $policy grants of the caller
     * @param string $userId authenticated user
     * @return array{description:string, messages:list<array{role:string, content:array{type:string, text:string}}>}|null
     *   the rendered prompt, or null when the name is unknown or not granted
     */
    public function get(string $name, GrantPolicy $policy, string $userId): ?array {
        if ($name !== self::EDIT_LOCALLY || $this->list($policy, $userId) === []) {
            return null;
        }
        return [
            'description' => Translator::t('Local editing workflow for a Nextcloud file.'),
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => self::text()]]],
        ];
    }

    /** @return string the workflow handed to the model, in fixed English */
    private static function text(): string {
        return implode("\n", [
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
            '',
            'A NEW file is not a checkout: files_checkout only replaces a file that already exists. To create a new file',
            'you generated locally (docx, xlsx, pdf, image, any type), call files_upload with the full path, show the plan',
            'to the user and confirm it; it returns uploadUrl, a single-use link valid for a few minutes. Send the file with',
            'curl -sS -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL".',
            'For a small text file (.md, .txt, .csv, .json, .html, .xml, .yaml) use files_create with the content inline.',
            'These tools never overwrite an existing name, and the folder must already exist (create it with files_mkdir).',
        ]);
    }
}
