<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;

/** Returns one admitted image preview and its metadata. */
final class ImageView {
	/**
	 * @param ImageFileAccess $files Visible image file resolution and validation.
	 * @param ImagePreview $preview Native image preview provider and byte-budget checks.
	 * @param ImageMetadata $metadata Image metadata and actor access formatter.
	 */
	public function __construct(
		private ImageFileAccess $files,
		private ImagePreview $preview,
		private ImageMetadata $metadata,
	) {
	}

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
		$file = $this->files->requireImage($root, $normalized);
		[$bytes, $mime, $effectiveSize] = $this->preview->renderPreview($file, $maxSize, $this->preview->singleMaxBytes());
		return ToolResult::image($bytes, $mime, $this->metadata->preview($file, $normalized, $userId, $effectiveSize));
	}
}
