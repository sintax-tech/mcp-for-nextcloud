<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\IRootFolder;

final class FileReader {
    public function __construct(
        private IRootFolder $rootFolder,
        private TextExtractor $extractor,
        private NodeAccessInfo $accessInfo,
        private OcrSupport $ocr,
        private FilesAccessGuard $visibilityGuard,
    ) {}

    public function readText(string $userId, string $path): FileRead {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(function () use ($root, $userId, $path): FileRead {
            $path = PathGuard::normalize($path);
            $file = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
            $meta = ['path' => $path, 'etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize(),
                'mime' => (string)$file->getMimetype(), 'access' => $this->accessInfo->describe($file, $userId)];
            $scannable = OcrSupport::canLackText($file->getName(), (string)$file->getMimetype());
            $isImage = $scannable && str_starts_with(strtolower((string)$file->getMimetype()), 'image/');
            $text = $isImage ? '' : $this->extractor->readText($file);
            if ($scannable && trim($text) === '') {
                $notice = $this->ocr->noTextNotice();
                return new FileRead($notice, $meta + ['text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice]);
            }
            return new FileRead($text, $meta);
        });
    }
}
