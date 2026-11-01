<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Policy;

/** Reads the loaded scope implementation for its negative source contracts. */
final class ScopeSources {
	/** Prevents instantiation of the source reader. */
	private function __construct() {
	}

	/**
	 * Keeps reflection-based file access outside the test class being moved.
	 *
	 * @param class-string $class loaded implementation to inspect
	 * @return string implementation source without transforming its contents
	 * @throws \ReflectionException when the class is unavailable
	 */
	public static function read(string $class): string {
		return (string)file_get_contents((string)(new \ReflectionClass($class))->getFileName());
	}
}
