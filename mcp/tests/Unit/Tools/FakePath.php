<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

/**
 * Path composition of the fake folders of {@see FakeTree}, as Nextcloud's Folder::get() and nodeExists() resolve a
 * relative path: an empty one (or one that is only slashes) is the folder itself, and a trailing slash names the same
 * node as without it.
 */
final class FakePath {
	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * The absolute path a relative path names below a folder.
	 *
	 * @param string $folder Absolute path of the folder, without a trailing slash.
	 * @param string $relative Path relative to it; '' is the folder itself.
	 * @return string Absolute path without a trailing slash.
	 */
	public static function below(string $folder, string $relative): string {
		return rtrim($folder . '/' . ltrim($relative, '/'), '/');
	}
}
