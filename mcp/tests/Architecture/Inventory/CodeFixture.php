<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Inventory;

/** Assembles fictional historical source from namespace segments; app moves must not rewrite fixture program text. */
final class CodeFixture {
	/** @param string $source Program text with ~ separating namespace segments. @return string PHP fixture source or class name. */
	public static function php(string $source): string { return str_replace('~', '\\', $source); }
	/** @param list<string> $segments Path segments without extension. @return string Fictional PHP path, not a filesystem reader. */
	public static function path(array $segments): string { return implode('/', $segments) . '.php'; }
}
