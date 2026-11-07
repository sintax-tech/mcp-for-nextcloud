<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

/**
 * Names of the two frontend bundles and the manifests that feed them.
 *
 * Manifest entries are paths relative to `src/` (`admin/picker.js`, `shared/connections.js`). The manifest files
 * themselves sit next to those trees. {@see self::REQUIRED} is the list of manifests that must exist; it is a
 * constant, not a directory listing. Both `src/admin.manifest` and `src/settings.manifest` are required.
 * Removing a file from disk never removes it from this list.
 */
final class AssetTargets {
	/**
	 * Manifest path relative to the app directory => bundle path relative to the app directory.
	 *
	 * @var array<string, string>
	 */
	public const MANIFESTS = [
		'src/admin.manifest' => 'js/mcp-admin.js',
		'src/settings.manifest' => 'js/mcp-personal.js',
	];

	/** Manifest of the personal settings page. Entries under `admin/` are refused here. */
	public const PERSONAL = 'src/settings.manifest';

	/** Prefix, relative to `src/`, of a fragment that belongs only to the admin bundle. */
	public const ADMIN_PREFIX = 'admin/';

	/**
	 * Manifests that must be present, as paths relative to the app directory.
	 *
	 * @var list<string>
	 */
	public const REQUIRED = ['src/admin.manifest', 'src/settings.manifest'];

	/**
	 * Whether a manifest entry is not a relative path contained in `src/`.
	 *
	 * The entry is the text of the line. A path that climbs out of `src/`, is absolute, or is not a `.js` file
	 * is refused before the file is opened.
	 *
	 * @param string $entry one manifest line, already trimmed
	 * @return string|null the problem, or null when the entry is a relative `.js` path
	 */
	public static function outside(string $entry): ?string {
		if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '\\') || str_contains($entry, '*/')
			|| str_starts_with($entry, '/') || !str_ends_with($entry, '.js')) {
			return "path outside src/: $entry";
		}
		foreach (explode('/', $entry) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return "path outside src/: $entry";
			}
		}
		return null;
	}
}
