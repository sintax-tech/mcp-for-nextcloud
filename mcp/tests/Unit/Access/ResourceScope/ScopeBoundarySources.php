<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

/** Finds native-enumerator references for the resource-scope boundary contract. */
final class ScopeBoundarySources {
	/** Prevents instantiation of the source scanner. */
	private function __construct() {
	}

	/** @return list<string> sorted lib-relative paths mentioning the native enumerator */
	public static function enumeratorCallers(): array {
		$root = dirname(__DIR__, 4) . '/lib';
		$users = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
			if (str_contains((string)file_get_contents((string)$file), 'NativeResourceEnumerator')) {
				$users[] = substr((string)$file, strlen($root) + 1);
			}
		}
		sort($users);
		return $users;
	}
}
