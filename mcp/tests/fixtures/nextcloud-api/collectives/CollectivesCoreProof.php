<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** A PHP-core proof identifies an actual internal declaration, never a class that happens to have a familiar name. */
final class CollectivesCoreProof {
	/**
	 * @param string $leaf the canonical `php-core Class::method` leaf
	 * @return void rejects invented methods, userland classes and aliases of the declaring class
	 */
	public static function check(string $leaf): void {
		if (!str_starts_with($leaf, 'php-core ')) {
			throw new RuntimeException('Not a PHP-core leaf: ' . $leaf);
		}
		[$class, $method] = explode('::', substr($leaf, 9));
		if (!class_exists($class, false)) {
			throw new RuntimeException('Not an internal PHP class: ' . $leaf);
		}
		$reflection = new ReflectionClass($class);
		if (!$reflection->isInternal() || !$reflection->hasMethod($method)
			|| $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
			throw new RuntimeException('Not an internal PHP declaration: ' . $leaf);
		}
	}
}
