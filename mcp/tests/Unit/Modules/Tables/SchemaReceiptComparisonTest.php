<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Writing\SchemaReceiptComparison;
use PHPUnit\Framework\TestCase;

/** Native serialization order and Column DTO float fields must not induce a repeat mutation. */
final class SchemaReceiptComparisonTest extends TestCase {
	/** @return void Table jsonSerialize orders emoji before description, unlike the input schema. */
	public function testNativeTableOrderIsObserved(): void {
		$this->assertSame([], SchemaReceiptComparison::differences(['title' => 'A', 'description' => 'D', 'emoji' => 'X'], ['id' => 1, 'title' => 'A', 'emoji' => 'X', 'description' => 'D']));
	}
	/** @return void Native Column number DTOs use floats, while clients may pass exact integers. */
	public function testNativeColumnNumericNormalizationAndMapOrder(): void {
		$this->assertSame([], SchemaReceiptComparison::differences(['description' => 'D', 'mandatory' => false, 'numberDefault' => 5, 'numberMin' => 1], ['numberMin' => 1.0, 'numberDefault' => 5.0, 'mandatory' => false, 'description' => 'D']));
		$this->assertSame(['numberDefault'], SchemaReceiptComparison::differences(['numberDefault' => 5], ['numberDefault' => 5.1]));
		$this->assertFalse(SchemaReceiptComparison::same(1, '1', 'numberMin'));
		$this->assertFalse(SchemaReceiptComparison::same(false, 0));
		$this->assertFalse(SchemaReceiptComparison::same([1, 2], [2, 1]));
		$this->assertTrue(SchemaReceiptComparison::same([['columnId' => 1, 'order' => 0]], [['order' => 0, 'columnId' => 1]]));
	}
}
