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
 * Each member still carries the name as written in the archive. This class applies
 * {@see ArchiveMembers::canonical()} before it captures a body, counts a duplicate, refuses `mcp/src/` or
 * compares a bundle with {@see AssetBuilder::expectedBundles()}. `tar` installs `mcp/js/./mcp-admin.js` as
 * `mcp/js/mcp-admin.js` and keeps the last copy. A symlink or hard link is not those bytes. `..` and an
 * absolute name are refused rather than resolved.
 */
final class PackagedAssets {
	/**
	 * Problems in the archive members `check_package.php` has already read.
	 *
	 * The tree check stays in {@see AssetBuilder::packageProblems()}. This method looks at the member names and
	 * at the captured bundle bodies. It does not rebuild from the archive.
	 *
	 * @param string $root app directory, the working directory of the package
	 * @param list<array{name: string, dir: bool, pax: bool, link?: bool, unsafe?: bool, body?: string}> $members archive entries, in order
	 * @return list<string> sorted problems, empty when the packaged bundles match the tree
	 */
	public static function problems(string $root, array $members): array {
		$problems = [];
		$counts = [];
		$bodies = [];
		$linkLast = [];
		foreach ($members as $member) {
			$written = $member['name'];
			if ($member['unsafe'] ?? false) {
				$problems[] = 'unsafe archive member: ' . $written;
				continue;
			}
			$name = ArchiveMembers::canonical($written);
			if ($name === null) {
				$problems[] = 'unsafe archive member: ' . $written;
				continue;
			}
			if ($member['link'] ?? false) {
				$problems[] = "archive link: $name";
			}
			if ($name === 'mcp/src' || str_starts_with($name, 'mcp/src/')) {
				$problems[] = "packaged source: $name";
			}
			if ($member['dir']) {
				continue;
			}
			$counts[$name] = ($counts[$name] ?? 0) + 1;
			$link = $member['link'] ?? false;
			$linkLast[$name] = $link;
			if (!$link && array_key_exists('body', $member)) {
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
				if (($linkLast[$name] ?? false) || $installed !== $bytes) {
					$problems[] = "packaged bundle differs: $name";
				}
			}
		}
		$problems = array_values(array_unique($problems));
		sort($problems, SORT_STRING);
		return $problems;
	}
}
