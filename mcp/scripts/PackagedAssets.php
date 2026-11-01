<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

/**
 * Compares the bundles stored in an app archive with the build of the tree being packaged.
 *
 * `tar` keeps the last copy when a member name is repeated, so a duplicate file member is refused even when the
 * first copy matches. A `mcp/src/` entry ships source that the package must not contain. A bundle member has to
 * be the bytes {@see AssetBuilder::expectedBundles()} would write, not merely a file of the right name.
 */
final class PackagedAssets {
	/**
	 * Problems in the archive members `check_package.php` has already read.
	 *
	 * The tree check stays in {@see AssetBuilder::packageProblems()}. This method looks at the member names and
	 * at the captured bundle bodies. It does not rebuild from the archive.
	 *
	 * @param string $root app directory, the working directory of the package
	 * @param list<array{name: string, dir: bool, pax: bool, body?: string}> $members archive entries, in order
	 * @return list<string> sorted problems, empty when the packaged bundles match the tree
	 */
	public static function problems(string $root, array $members): array {
		$problems = [];
		$counts = [];
		$bodies = [];
		foreach ($members as $member) {
			$name = $member['name'];
			if ($name === 'mcp/src' || str_starts_with($name, 'mcp/src/')) {
				$problems[] = "packaged source: $name";
			}
			if ($member['dir']) {
				continue;
			}
			$counts[$name] = ($counts[$name] ?? 0) + 1;
			if (array_key_exists('body', $member)) {
				$bodies[$name][] = $member['body'];
			}
		}
		foreach ($counts as $name => $count) {
			if ($count > 1) {
				$problems[] = "duplicate archive member: $name";
			}
		}
		$expected = AssetBuilder::expectedBundles($root);
		if ($expected !== null) {
			foreach ($expected as $name => $bytes) {
				$copies = $bodies[$name] ?? [];
				if (($counts[$name] ?? 0) === 0) {
					$problems[] = "missing packaged bundle: $name";
					continue;
				}
				$installed = $copies === [] ? null : $copies[array_key_last($copies)];
				if ($installed !== $bytes) {
					$problems[] = "packaged bundle differs: $name";
				}
			}
		}
		$problems = array_values(array_unique($problems));
		sort($problems, SORT_STRING);
		return $problems;
	}
}
