<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Locating;

use InvalidArgumentException;
use OCA\Mcp\Platform\FileAccess\PathGuard;

/**
 * Basenames the Notes policy evaluates.
 *
 * The sanitizer stays on the repository. This class only projects the name a create would use and the last
 * segment of a destination the handlers already built.
 */
final class NoteNames {
	/**
	 * Static projections only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The basename `notes_create` projects before a collision suffix and before any folder exists.
	 *
	 * `freeName` may append ` (n)`, and that suffix keeps this extension.
	 *
	 * @param string $title Raw title.
	 * @return string Sanitized title plus `.md`.
	 * @throws InvalidArgumentException When the title is empty after sanitizing.
	 */
	public static function created(string $title): string {
		return NotesRepository::title($title) . '.md';
	}

	/**
	 * Last segment of a destination that is already a path.
	 *
	 * Splits the way {@see PathGuard::segments()} does, so the projected name does not depend on the process locale.
	 *
	 * @param string $path Absolute destination. Only its last segment is returned.
	 * @return string That segment, or an empty string when the path has none.
	 */
	public static function leaf(string $path): string {
		$segments = PathGuard::segments($path);
		$last = array_key_last($segments);
		return is_int($last) ? $segments[$last] : '';
	}
}
