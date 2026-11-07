<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Rows;

use PHPUnit\Framework\Assert;

/** Checks the promised MCP order while retaining the native content oracle. */
final class RowsNativeComparison {
	/**
	 * Views retain native order; unsorted tables promise row ID ASC independently of native SQL order.
	 *
	 * @param list<array<string, mixed>> $mcp Rows in the actual MCP response order
	 * @param list<array<string, mixed>> $native Rows returned by RowService in the same request
	 * @param bool $table Whether the target is an unsorted table rather than a sorted view
	 * @param string $label Assertion context
	 * @return list<array<string, mixed>> Native rows matched to the unchanged MCP order for cell assertions
	 */
	public static function assertOrderAndMatch(array $mcp, array $native, bool $table, string $label): array {
		$expectedIds = array_column($native, 'id');
		if ($table) {
			sort($expectedIds, SORT_NUMERIC);
		}
		Assert::assertSame($expectedIds, array_column($mcp, 'id'), "$label: rows and order");
		$nativeById = array_column($native, null, 'id');
		return array_map(static fn (array $row): array => $nativeById[$row['id']], $mcp);
	}
}
