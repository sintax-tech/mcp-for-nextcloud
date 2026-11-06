<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Delegates the existing image tool APIs to focused image work classes. */
final class ImageTools {
	/** Default max_size (in pixels) for a single image preview. */
	public const DEFAULT_MAX_SIZE = ImageLimits::DEFAULT_MAX_SIZE;
	/** Lower bound of max_size. */
	public const MIN_MAX_SIZE = ImageLimits::MIN_MAX_SIZE;
	/** Upper bound of max_size. */
	public const MAX_MAX_SIZE = ImageLimits::MAX_MAX_SIZE;
	/** App config key for the per-image byte limit. */
	public const SINGLE_MAX_BYTES_KEY = ImageLimits::SINGLE_MAX_BYTES_KEY;
	/** App config key for the batch total byte budget. */
	public const BATCH_MAX_BYTES_KEY = ImageLimits::BATCH_MAX_BYTES_KEY;
	/** Default per-image byte limit. */
	public const DEFAULT_SINGLE_MAX_BYTES = ImageLimits::DEFAULT_SINGLE_MAX_BYTES;
	/** Default total byte budget for files_images_view. */
	public const DEFAULT_BATCH_MAX_BYTES = ImageLimits::DEFAULT_BATCH_MAX_BYTES;
	/** Maximum number of images requested in a batch call. */
	public const BATCH_MAX_ITEMS = ImageLimits::BATCH_MAX_ITEMS;
	/** Default limit of files_image_search. */
	public const SEARCH_DEFAULT_LIMIT = ImageLimits::SEARCH_DEFAULT_LIMIT;
	/** Hard ceiling for files_image_search. */
	public const SEARCH_MAX_LIMIT = ImageLimits::SEARCH_MAX_LIMIT;
	/** Hard ceiling on object IDs resolved for a system tag search. */
	public const TAG_RESOLUTION_CEILING = ImageLimits::TAG_RESOLUTION_CEILING;
	/** Object type used by the Tags app for files. */
	public const TAG_OBJECT_TYPE = ImageLimits::TAG_OBJECT_TYPE;
	/**
	 * @param ImageView $view Single-image content and metadata workflow.
	 * @param ImageBatch $batch Multi-image selection and shared byte-budget workflow.
	 * @param ImageSearchHandler $search Image query admission and search dispatch.
	 * @param ImageMetadata $metadata Image metadata and actor access formatter.
	 */
	public function __construct(
		private ImageView $view,
		private ImageBatch $batch,
		private ImageSearchHandler $search,
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
		return $this->view->view($root, $userId, $path, $maxSize);
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
		return $this->batch->viewMany($root, $userId, $paths, $folder, $limit, $maxSize);
	}

	/**
	 * Search images only; filter by name, folder, mtime range and system tag. Returns metadata only;
	 * use view/viewMany to fetch bytes.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param array{query?:string, folder?:string, modified_after?:string, modified_before?:string, tag?:string, limit:int} $args
	 * @return list<array<string, mixed>>
	 * @throws ToolFailure for an invalid folder or date
	 */
	public function search(Folder $root, string $userId, array $args): array {
		return $this->search->execute($root, $userId, $args, $this->metadata->searchEntry(...));
	}

	/** @return bool whether the mimetype belongs to the image/* family or is a PDF (previews only). */
	public static function looksLikeImage(string $mime): bool {
		return ImageFileAccess::looksLikeImage($mime);
	}
}
