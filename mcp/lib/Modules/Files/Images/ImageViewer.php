<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCP\IConfig;

final class ImageViewer {
    public const DEFAULT_MAX_SIZE = 1568;
    public const MIN_MAX_SIZE = 256;
    public const MAX_MAX_SIZE = 2048;
    public const SINGLE_MAX_BYTES_KEY = 'image_single_max_bytes';
    public const BATCH_MAX_BYTES_KEY = 'image_batch_max_bytes';
    public const DEFAULT_SINGLE_MAX_BYTES = 1 * 1024 * 1024;
    public const DEFAULT_BATCH_MAX_BYTES = 4 * 1024 * 1024;
    public const BATCH_MAX_ITEMS = 6;

    public function __construct(
        private IConfig $config,
        private ImageSelector $selector,
        private ImageRenderer $renderer,
        private ImageMetadata $metadata,
    ) {}

    /**
     * One image. The content array holds the image block, followed by a text block with metadata.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path user-relative path
     * @param int $maxSize max dimension in pixels
     * @return array{content: list<array<string, mixed>>}
     * @throws ToolFailure for a non-image, missing provider or budget exhaustion
     */
    public function view(Folder $root, string $userId, string $path, int $maxSize): array {
        $normalized = PathGuard::normalize($path);
        $file = $this->selector->requireImage($root, $normalized);
        [$bytes, $mime, $effectiveSize] = $this->renderer->renderPreview($file, $maxSize, $this->singleMaxBytes());
        return ToolResult::image($bytes, $mime, $this->metadata->imageMetadata($file, $normalized, $userId, $effectiveSize));
    }

    /**
     * Several images, under a shared byte budget. Pass either paths (up to BATCH_MAX_ITEMS) or folder
     * (plus optional limit) to pick the most recently modified images under that folder. Skipped items
     * are reported in the trailing summary.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param list<string>|null $paths explicit paths, or null to pick from $folder
     * @param string|null $folder folder path when $paths is null
     * @param int $limit maximum items when picking from $folder
     * @param int $maxSize max dimension in pixels per image
     * @return array{content: list<array<string, mixed>>}
     * @throws ToolFailure when neither or both inputs are provided, or when the folder is unreachable
     */
    public function viewMany(Folder $root, string $userId, ?array $paths, ?string $folder, int $limit, int $maxSize): array {
        if ($paths === null && $folder === null) {
            throw new ToolFailure(ImageMessages::imagesNoTarget());
        }
        if ($paths !== null && $folder !== null) {
            throw new ToolFailure(ImageMessages::imagesConflict());
        }
        $selected = $paths !== null
            ? $this->selector->selectPaths($root, $paths)
            : $this->selector->selectFromFolder($root, $userId, (string)$folder, $limit);
        $budget = $this->batchMaxBytes();
        $items = [];
        $skipped = [];
        $used = 0;
        foreach ($selected as $entry) {
            [$path, $file, $error] = $entry;
            if ($error !== null) {
                $skipped[] = ['path' => $path, 'reason' => $error];
                continue;
            }
            $remaining = $budget - $used;
            if ($remaining <= 0) {
                $skipped[] = ['path' => $path, 'reason' => 'batch budget exhausted'];
                continue;
            }
            try {
                [$bytes, $mime, $effective] = $this->renderer->renderPreview($file, $maxSize, min($this->singleMaxBytes(), $remaining));
            } catch (ToolFailure $e) {
                $skipped[] = ['path' => $path, 'reason' => $e->getMessage()];
                continue;
            }
            $used += strlen($bytes);
            $items[] = [
                'bytes' => $bytes,
                'mimeType' => $mime,
                'metadata' => $this->metadata->imageMetadata($file, $path, $userId, $effective),
            ];
        }
        $summary = [
            'returned' => count($items),
            'skipped' => $skipped,
            'bytes_used' => $used,
            'bytes_budget' => $budget,
        ];
        return ToolResult::images($items, $summary);
    }

    /** @return int per-image byte limit, from app config with a sane default and a lower floor */
    private function singleMaxBytes(): int {
        $configured = (int)$this->config->getAppValue('mcp', self::SINGLE_MAX_BYTES_KEY, (string)self::DEFAULT_SINGLE_MAX_BYTES);
        return $configured > 0 ? $configured : self::DEFAULT_SINGLE_MAX_BYTES;
    }

    /** @return int batch budget, from app config with a sane default */
    private function batchMaxBytes(): int {
        $configured = (int)$this->config->getAppValue('mcp', self::BATCH_MAX_BYTES_KEY, (string)self::DEFAULT_BATCH_MAX_BYTES);
        return $configured > 0 ? $configured : self::DEFAULT_BATCH_MAX_BYTES;
    }
}
