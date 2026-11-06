<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;

/** Admits readable image nodes through the existing Files visibility guard. */
final class ImageFileAccess {
	/**
	 * @param ?FilesAccessGuard $visibilityGuard Existing optional Files visibility guard.
	 */
	public function __construct(
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path user-relative path
	 * @return File readable image file
	 * @throws ToolFailure for a folder, a non-image or an unreadable node
	 */
	public function requireImage(Folder $root, string $path): File {
		$node = NodeAccess::get($root, $path, $this->visibilityGuard);
		if (!$node instanceof File) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ImageMessages::notAnImage());
		}
		if (!self::looksLikeImage((string)$node->getMimetype())) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ImageMessages::notAnImage());
		}
		return $node;
	}

	/** @return bool whether the mimetype belongs to the image/* family or is a PDF (previews only). */
	public static function looksLikeImage(string $mime): bool {
		$mime = strtolower($mime);
		return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
	}
}
