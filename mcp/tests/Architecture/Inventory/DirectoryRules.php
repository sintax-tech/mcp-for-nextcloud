<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Inventory;

/** Folder names express work rather than generic technical buckets. */
final class DirectoryRules {
	/** @param list<string> $paths Directories or files relative to the app. @return list<string> Invalid paths. */
	public static function problems(array $paths): array {
		$errors = [];
		foreach ($paths as $path) {
			if (str_starts_with($path, 'lib/Modules/') && in_array('Handler', explode('/', $path), true)) { $errors[] = 'Forbidden module directory: ' . $path; }
			if (preg_match('#^lib/Modules/Files/([^/]+)\.php$#D', $path, $match) && !in_array($match[1], ['FilesModule', 'FilesToolCatalog', 'FilesRegistration', 'FilesCallContext', 'FilesPreviewContext'], true)) { $errors[] = 'Unassigned Files root work: ' . $path; }
			if (array_intersect(explode('/', dirname($path . '/.')), ['Services', 'Helpers', 'Utils']) !== []) { $errors[] = 'Forbidden directory: ' . $path; }
		}
		$verbs = [];
		foreach ($paths as $path) {
			if (preg_match('#^lib/Modules/[^/]+/(?!(?:Tools|Integration)/)[^/]+/[^/]+\.php$#', $path)) { $verbs[dirname($path)][] = $path; }
		}
		foreach ($verbs as $directory => $members) { if (count($members) < 2) { $errors[] = 'Single class in work directory: ' . $directory; } }
		return $errors;
	}
}
