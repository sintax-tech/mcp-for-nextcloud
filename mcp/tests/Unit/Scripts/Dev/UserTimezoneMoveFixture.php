<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

/** Reconstructs both sides of the timezone move so its comparator controls never become skips. */
final class UserTimezoneMoveFixture {
	/** Prevents instantiation of this fixture utility. */
	private function __construct() {
	}

	/**
	 * Uses the real tree as the old or new side and simulates the opposite direction.
	 *
	 * @param array<string,string> $tree current app sources
	 * @param callable $move existing fixture operation accepting production/test segments and imports
	 * @return array{0: array<string,string>, 1: array<string,string>} trees before and after the move
	 */
	public static function trees(array $tree, callable $move): array {
		$old = ['Service', 'UserTimezone'];
		$new = ['Platform', 'Time', 'UserTimezone'];
		$oldTest = ['UserTimezoneTest'];
		$newTest = ['Platform', 'Time', 'UserTimezoneTest'];
		if (isset($tree['lib/' . implode('/', $old) . '.php'])) {
			return [$tree, $move($old, $new, $oldTest, $newTest, [])];
		}
		return [$move($new, $old, $newTest, $oldTest, []), $tree];
	}
}
