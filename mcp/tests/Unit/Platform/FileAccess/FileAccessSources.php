<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\FileAccess;

use OCA\Mcp\Tests\Unit\ClassSource;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;

/** Reads the exact production sources inspected by the user-facing message regression. */
final class FileAccessSources {
	/** Prevents instantiation of this fixture utility. */
	private function __construct() {
	}

	/**
	 * Locates either inspected class through its declaration, independently of the test's directory.
	 *
	 * @param string $class short name from the regression's fixed class list
	 * @return string original PHP source bytes
	 * @throws \UnhandledMatchError when asked to inspect a class outside the fixed list
	 * @throws \ReflectionException when an inspected production class cannot be loaded
	 */
	public static function read(string $class): string {
		$fqcn = match ($class) {
			'NodeAccessInfo' => NodeAccessInfo::class,
			'SharedWriteGuard' => SharedWriteGuard::class,
		};
		return ClassSource::read($fqcn);
	}
}
