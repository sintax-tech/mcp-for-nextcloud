<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

/** Records which probe tools the container built, so a test sees that listing builds none. */
final class BuiltTools {
	/** @var list<string> names of the probe tools built so far, in order */
	public static array $names = [];
}
