<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

final class ImageRenderer {
    public const RETURNABLE_ORIGINAL_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private IPreview $preview,
        private LoggerInterface $logger,
    ) {}

    /**
     * Renders a preview, shrinking max_size until the bytes fit the per-image limit.
     *
     * @param File $file source image
     * @param int $maxSize initial max dimension in pixels
     * @param int $byteLimit per-image byte limit
     * @return array{0:string, 1:string, 2:int} bytes, mimeType, effective max_size used
     * @throws ToolFailure when no preview can be produced and the original cannot be returned
     */
    public function renderPreview(File $file, int $maxSize, int $byteLimit): array {
        $current = $maxSize;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $simple = $this->preview->getPreview($file, $current, $current, false);
            } catch (\Throwable $e) {
                $this->logger->debug('MCP image preview failed', ['app' => 'mcp', 'exception_class' => $e::class]);
                break;
            }
            $bytes = $simple->getContent();
            if (strlen($bytes) <= $byteLimit) {
                return [$bytes, $simple->getMimeType(), $current];
            }
            $current = max(ImageViewer::MIN_MAX_SIZE, (int)floor($current / 2));
            if ($current === ImageViewer::MIN_MAX_SIZE && strlen($bytes) <= $byteLimit) {
                return [$bytes, $simple->getMimeType(), $current];
            }
        }
        // Preview missing or still too big: fall back to the original when it is a safe raster format
        // under the byte limit; otherwise the file cannot be shown.
        $mime = strtolower((string)$file->getMimetype());
        if (in_array($mime, self::RETURNABLE_ORIGINAL_MIMES, true)) {
            $size = (int)$file->getSize();
            if ($size > 0 && $size <= $byteLimit) {
                return [(string)$file->getContent(), $mime, $maxSize];
            }
            throw new ToolFailure(ImageMessages::imagePreviewTooLarge());
        }
        throw new ToolFailure(ImageMessages::imageUnsupported());
    }
}
