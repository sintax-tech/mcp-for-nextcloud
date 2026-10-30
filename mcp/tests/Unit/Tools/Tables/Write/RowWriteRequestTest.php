<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Tables\Write\RowWriteRequest;
use PHPUnit\Framework\TestCase;

/**
 * The arguments of the row writes: exactly one target, and the cells as 1 to 100 closed objects {column_id, value} with
 * each positive column id once; anything else is an invalid call, before any service.
 */
final class RowWriteRequestTest extends TestCase {
	/**
	 * Malformed cell lists and targets are invalid calls.
	 *
	 * @return void
	 */
	public function testMalformedArgumentsAreInvalid(): void {
		$cell = ['column_id' => 11, 'value' => 'a'];
		foreach ([
			'no cells' => ['table_id' => 1],
			'empty list' => ['table_id' => 1, 'cells' => []],
			'101 cells' => ['table_id' => 1, 'cells' => array_map(static fn (int $i): array => ['column_id' => $i, 'value' => 'a'], range(1, 101))],
			'a map instead of a list' => ['table_id' => 1, 'cells' => ['a' => $cell]],
			'a list item' => ['table_id' => 1, 'cells' => [[11, 'a']]],
			'an extra key' => ['table_id' => 1, 'cells' => [$cell + ['old' => 'x']]],
			'no value' => ['table_id' => 1, 'cells' => [['column_id' => 11]]],
			'no column' => ['table_id' => 1, 'cells' => [['value' => 'a']]],
			'a repeated column' => ['table_id' => 1, 'cells' => [$cell, ['column_id' => 11, 'value' => 'b']]],
			'a meta column' => ['table_id' => 1, 'cells' => [['column_id' => -1, 'value' => 1]]],
			'a column as text' => ['table_id' => 1, 'cells' => [['column_id' => '11', 'value' => 'a']]],
			'both targets' => ['table_id' => 1, 'view_id' => 2, 'cells' => [$cell]],
			'no target' => ['cells' => [$cell]],
		] as $label => $arguments) {
			try {
				RowWriteRequest::of($arguments, false);
				$this->fail('accepted: ' . $label);
			} catch (ArgumentValidationException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * One and a hundred cells are accepted, in order, null values included.
	 *
	 * @return void
	 */
	public function testTheBoundsAreAccepted(): void {
		$one = RowWriteRequest::of(['view_id' => 4, 'row_id' => 9, 'cells' => [['column_id' => 11, 'value' => null]], 'confirm_shared' => true], true);
		$this->assertSame(['view', 4, 9, [11 => null], true], [$one->targetType, $one->targetId, $one->rowId, $one->cells, $one->confirmShared]);
		$hundred = RowWriteRequest::of(['table_id' => 1, 'cells' => array_map(static fn (int $i): array => ['column_id' => $i, 'value' => $i], range(1, 100))], false);
		$this->assertSame(range(1, 100), array_keys($hundred->cells));
		$this->assertNull($hundred->rowId);
	}
}
