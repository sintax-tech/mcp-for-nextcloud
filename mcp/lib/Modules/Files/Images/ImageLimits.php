<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;



/** Defines the unchanged image tool limits and configuration keys. */
final class ImageLimits {
	/** Default max_size (in pixels) for a single image preview. */
	public const DEFAULT_MAX_SIZE = 1568;
	/** Lower bound of max_size. */
	public const MIN_MAX_SIZE = 256;
	/** Upper bound of max_size. */
	public const MAX_MAX_SIZE = 2048;
	/** App config key for the per-image byte limit. */
	public const SINGLE_MAX_BYTES_KEY = 'image_single_max_bytes';
	/** App config key for the batch total byte budget. */
	public const BATCH_MAX_BYTES_KEY = 'image_batch_max_bytes';
	/** Default per-image byte limit. */
	public const DEFAULT_SINGLE_MAX_BYTES = 1 * 1024 * 1024;
	/** Default total byte budget for files_images_view. */
	public const DEFAULT_BATCH_MAX_BYTES = 4 * 1024 * 1024;
	/** Maximum number of images requested in a batch call. */
	public const BATCH_MAX_ITEMS = 6;
	/** Default limit of files_image_search. */
	public const SEARCH_DEFAULT_LIMIT = 25;
	/** Hard ceiling for files_image_search. */
	public const SEARCH_MAX_LIMIT = 100;
	/** Hard ceiling on object IDs resolved for a system tag search. */
	public const TAG_RESOLUTION_CEILING = 2000;
	/** Object type used by the Tags app for files. */
	public const TAG_OBJECT_TYPE = 'files';
	/** Prevents construction of the immutable image limits. */
	private function __construct() {
	}
}
