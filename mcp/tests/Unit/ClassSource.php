<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

/** Reads a declared class's original source bytes for source-level regressions. */
final class ClassSource {
	/** Prevents instantiation of this shared test support. */
	private function __construct() {
	}

	/**
	 * Resolves the declaration independently of the caller's namespace or directory.
	 *
	 * @param class-string $class declared class to inspect
	 * @return string original PHP source bytes
	 * @throws \ReflectionException when the requested class cannot be loaded
	 */
	public static function read(string $class): string {
		return (string)file_get_contents((new \ReflectionClass($class))->getFileName());
	}
}
