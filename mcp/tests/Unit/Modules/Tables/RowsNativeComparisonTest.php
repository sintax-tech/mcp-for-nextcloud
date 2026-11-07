<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Tests\Integration\Tables\Rows\RowsNativeComparison;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** Verifies that unordered native tables never weaken the MCP ordering or content oracle. */
final class RowsNativeComparisonTest extends TestCase {
	/** @return void Native cells stay attached to their row despite a different native table order. */
	public function testTableMatchesNativeContentById(): void {
		$native = [['id' => 6, 'data' => ['six']], ['id' => 1, 'data' => ['one']]];
		$this->assertSame([$native[1], $native[0]], RowsNativeComparison::assertOrderAndMatch([['id' => 1], ['id' => 6]], $native, true, 'table'));
	}

	/** @return void MCP output is never reordered to hide a violation of its ascending ID promise. */
	public function testTableRejectsUnsortedMcp(): void {
		$this->expectException(AssertionFailedError::class);
		RowsNativeComparison::assertOrderAndMatch([['id' => 6], ['id' => 1]], [['id' => 6], ['id' => 1]], true, 'table');
	}

	/** @return void Missing or repeated MCP rows cannot pass the native row-set comparison. */
	public function testTableRejectsDifferentRowSet(): void {
		$this->expectException(AssertionFailedError::class);
		RowsNativeComparison::assertOrderAndMatch([['id' => 1], ['id' => 1]], [['id' => 6], ['id' => 1]], true, 'table');
	}

	/** @return void View comparisons continue to require exactly the native order. */
	public function testViewRejectsDifferentOrder(): void {
		$this->expectException(AssertionFailedError::class);
		RowsNativeComparison::assertOrderAndMatch([['id' => 1], ['id' => 6]], [['id' => 6], ['id' => 1]], false, 'view');
	}
}
