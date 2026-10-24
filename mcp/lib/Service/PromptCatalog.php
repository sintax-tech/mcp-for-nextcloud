<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Service\Policy\GrantUnknown;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
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
        // A prompt whose grant is unknown (GrantUnknown) is left out, alone; asking for it directly is a 503 (get()).
        $granted = static function (string $operation) use ($policy, $userId): bool {
            try {
                return $policy->granted($userId, self::MODULE, $operation);
            } catch (GrantUnknown) {
                return false;
            }
        };
        $prompts = [];
        if ($granted(self::OPERATION)) {
            $prompts[] = [
                'name' => self::EDIT_LOCALLY,
                'title' => Translator::t('Edit a Nextcloud file locally'),
                'description' => Translator::t('Teaches how to download a Nextcloud file, edit it with local tools, and send it back without passing the content through the model.'),
                'arguments' => [],
            ];
        }
        if ($granted(self::OPERATION_CREATE)) {
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
     * @throws PolicyUnavailable when the policy cannot be read or a grant it needs is unknown (503)
     */
    public function get(string $name, GrantPolicy $policy, string $userId): ?array {
        // Only the grants this prompt needs, read without omission: an unknown one surfaces as a 503.
        $operation = match ($name) {
            self::EDIT_LOCALLY => self::OPERATION,
            self::CREATE_FILE => self::OPERATION_CREATE,
            default => null,
        };
        if ($operation === null || !$policy->granted($userId, self::MODULE, $operation)) {
            return null;
        }
        $create = $name === self::EDIT_LOCALLY && $policy->granted($userId, self::MODULE, self::OPERATION_CREATE);
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
        return CheckoutPrompt::text($create, self::createLines());
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
