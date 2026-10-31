<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

/** Prevents a temporary repository test from inheriting Git routing, signing or hooks from its caller. */
final class IsolatedGitEnvironment {
	/**
	 * Restores every inherited Git variable even if the test fails or skips; HOME and PATH are untouched.
	 *
	 * @param callable():void $test repository test body
	 * @return void
	 */
	public static function run(callable $test): void {
		$inherited = array_filter(getenv(), static fn (string $name): bool => str_starts_with($name, 'GIT_'), ARRAY_FILTER_USE_KEY);
		foreach ($inherited as $name => $value) {
			putenv($name);
		}
		putenv('GIT_CONFIG_NOSYSTEM=1');
		putenv('GIT_CONFIG_GLOBAL=/dev/null');
		try {
			$test();
		} finally {
			foreach (getenv() as $name => $value) {
				if (str_starts_with($name, 'GIT_')) {
					putenv($name);
				}
			}
			foreach ($inherited as $name => $value) {
				putenv($name . '=' . $value);
			}
		}
	}
}
