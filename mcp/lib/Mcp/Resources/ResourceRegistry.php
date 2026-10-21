<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Resources;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Grants\GrantPolicy;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\Modules\Files\Contracts\FileResourceReader;
use OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuide;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;

/**
 * Registry of MCP resources and resource templates for the MCP for Cloud app.
 *
 * Implements the minimum approved MCP resource model:
 * - resources/list: only mcp://guide (the rendered ToolGuide overview)
 * - resources/templates/list: nc://files/{path} and nc://notes/{id} (filtered by user grants)
 * - resources/read: text extraction for files/notes, small base64 blobs for binary files,
 *   with strict visibility guard assertions and grant verification.
 */
class ResourceRegistry {
    public const GUIDE_URI = 'mcp://guide';
    public const FILES_PREFIX = 'nc://files/';
    public const NOTES_PREFIX = 'nc://notes/';

    /** Maximum size for binary blobs returned via resources/read (512 KiB). */
    public const MAX_BLOB_BYTES = FileResourceReader::MAX_BLOB_BYTES;
    /** Maximum characters returned for extracted text before truncation. */
    public const MAX_TEXT_CHARS = FileResourceReader::MAX_TEXT_CHARS;

    public function __construct(
        private ToolRegistry $tools,
        private GrantPolicy $policy,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private FileResourceReader $fileReader,
        private NoteResourceReader $noteReader,
        private ToolGuide $guide,
        private ?GuestRestrictions $guests = null,
    ) {
    }

    /**
     * Lists standalone resources. Only mcp://guide is exposed.
     *
     * @param string $userId authenticated Nextcloud user
     * @param string|null $cursor optional cursor from previous page
     * @return array{resources: list<array{uri:string, name:string, title:string, description:string, mimeType:string}>}
     * @throws InvalidArgumentException when cursor is invalid
     */
    public function list(string $userId, ?string $cursor = null): array {
        if ($cursor !== null && $cursor !== '') {
            throw new InvalidArgumentException('Invalid cursor');
        }
        return [
            'resources' => [
                [
                    'uri' => self::GUIDE_URI,
                    'name' => ToolGuide::TOOL,
                    'title' => ToolPresentation::title(ToolGuide::TOOL),
                    'description' => 'Describes the tools this user can call, their parameters and limits.',
                    'mimeType' => 'text/markdown',
                ],
            ],
        ];
    }

    /**
     * Lists resource templates available to the user based on active grants and installed apps.
     *
     * @param string $userId authenticated Nextcloud user
     * @param string|null $cursor optional cursor from previous page
     * @return array{resourceTemplates: list<array{uriTemplate:string, name:string, title:string, description:string, mimeType:string}>}
     * @throws InvalidArgumentException when cursor is invalid
     */
    public function templates(string $userId, ?string $cursor = null): array {
        if ($cursor !== null && $cursor !== '') {
            throw new InvalidArgumentException('Invalid cursor');
        }
        $templates = [];
        // A template whose grant is unknown (GrantUnknown) is left out, alone; reading through it still answers 503.
        $listed = static function (\Closure $has): bool {
            try {
                return $has();
            } catch (GrantUnknown) {
                return false;
            }
        };
        if ($listed(fn (): bool => $this->hasFilesRead($userId))) {
            $templates[] = [
                'uriTemplate' => 'nc://files/{path}',
                'name' => 'files',
                'title' => ToolPresentation::moduleTitle('files'),
                'description' => 'Read Nextcloud file content by path.',
                'mimeType' => 'application/octet-stream',
            ];
        }
        if ($listed(fn (): bool => $this->hasNotesRead($userId))) {
            $templates[] = [
                'uriTemplate' => 'nc://notes/{id}',
                'name' => 'notes',
                'title' => ToolPresentation::moduleTitle('notes'),
                'description' => 'Read Nextcloud note content by note ID.',
                'mimeType' => 'text/markdown',
            ];
        }
        return [
            'resourceTemplates' => $templates,
        ];
    }

    /**
     * Reads the resource at the given URI.
     *
     * @param string $uri full resource URI
     * @param string $userId authenticated Nextcloud user
     * @return array{contents: list<array<string, mixed>>}
     * @throws ToolFailure when ungranted, not found, hidden, or too large
     * @throws InvalidArgumentException on invalid URI structure
     */
    public function read(string $uri, string $userId): array {
        if ($uri === self::GUIDE_URI) {
            return $this->readGuide($userId);
        }
        if (str_starts_with($uri, self::FILES_PREFIX)) {
            return $this->readFiles($uri, $userId);
        }
        if (str_starts_with($uri, self::NOTES_PREFIX)) {
            return $this->readNotes($uri, $userId);
        }
        throw new ToolFailure(FileAccessMessages::notFound());
    }

    /**
     * Checks if user has files.read grant.
     */
    public function hasFilesRead(string $userId): bool {
        try {
            return $this->policy->granted($userId, 'files', 'read');
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Checks if user has notes.read grant and the notes app is enabled.
     */
    public function hasNotesRead(string $userId): bool {
        try {
            if (!$this->policy->granted($userId, 'notes', 'read')) {
                return false;
            }
        } catch (InvalidArgumentException) {
            return false;
        }
        $user = $this->userManager->get($userId);
        return $user !== null && $this->appManager->isEnabledForUser('notes', $user)
            && ($this->guests === null || $this->guests->allowsApp($userId, 'notes'));
    }

    /**
     * Renders the tool guide for this user's available tools.
     *
     * @return array{contents: list<array{uri:string, mimeType:string, text:string}>}
     */
    private function readGuide(string $userId): array {
        $definitions = $this->tools->definitions($userId);
        $result = $this->guide->result($definitions, []);
        $text = $result['content'][0]['text'] ?? '';
        return [
            'contents' => [
                [
                    'uri' => self::GUIDE_URI,
                    'mimeType' => 'text/markdown',
                    'text' => $text,
                ],
            ],
        ];
    }

    private function readFiles(string $uri, string $userId): array {
        if (!$this->hasFilesRead($userId)) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        return $this->fileReader->read($uri, $userId);
    }

    /**
     * Reads a Nextcloud note node via nc://notes/{id}.
     *
     * @return array{contents: list<array{uri:string, mimeType:string, text:string}>}
     */
    private function readNotes(string $uri, string $userId): array {
        if (!$this->hasNotesRead($userId)) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        return $this->noteReader->read($uri, $userId);
    }
}
