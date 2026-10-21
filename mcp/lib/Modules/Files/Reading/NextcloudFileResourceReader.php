<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use InvalidArgumentException;
use OCA\Mcp\Modules\Files\Contracts\FileResourceReader;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\IRootFolder;

final class NextcloudFileResourceReader implements FileResourceReader {
    public function __construct(
        private IRootFolder $rootFolder,
        private FilesAccessGuard $visibilityGuard,
        private TextExtractor $textExtractor,
    ) {}

    /**
     * Reads a Nextcloud file node via nc://files/{path}.
     *
     * @return array{contents: list<array<string, mixed>>}
     */
    public function read(string $uri, string $userId): array {
        $this->visibilityGuard->assertScopeReadable();
        $rawPath = rawurldecode(substr($uri, strlen('nc://files/')));
        $path = '/' . ltrim($rawPath, '/');
        try {
            $path = PathGuard::normalize($path);
        } catch (InvalidArgumentException) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }

        return NodeAccess::run(function () use ($uri, $path, $userId): array {
            $root = $this->rootFolder->getUserFolder($userId);
            // The guard runs before requireFile, so a protected item answers like a missing one instead of
            // revealing that it exists and is a folder.
            $node = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));

            $mime = (string)$node->getMimetype();
            $name = $node->getName();

            if (TextExtractor::canExtract($name, $mime)) {
                $text = $this->textExtractor->readText($node, self::MAX_TEXT_CHARS);
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => TextExtractor::textMime($name, $mime),
                            'text' => $text,
                        ],
                    ],
                ];
            }

            $size = (int)$node->getSize();
            if ($size > self::MAX_BLOB_BYTES) {
                throw new ToolFailure(ReadingMessages::binaryResourceTooLarge(self::MAX_BLOB_BYTES));
            }
            $bytes = (string)$node->getContent();
            if (strlen($bytes) > self::MAX_BLOB_BYTES) {
                throw new ToolFailure(ReadingMessages::binaryResourceTooLarge(self::MAX_BLOB_BYTES));
            }
            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => $mime !== '' ? $mime : 'application/octet-stream',
                        'blob' => base64_encode($bytes),
                    ],
                ],
            ];
        });
    }
}
