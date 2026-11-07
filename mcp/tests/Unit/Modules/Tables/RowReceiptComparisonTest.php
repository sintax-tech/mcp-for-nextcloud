<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Writing\RowReceiptComparison;
use PHPUnit\Framework\TestCase;

/**
 * The receipt of a row write tells a value Tables changed from one it only gave in another PHP type: a plain number is
 * compared by value, everything else strictly.
 */
final class RowReceiptComparisonTest extends TestCase {
	/** A plain number column. */
	private const NUMBER = ['type' => 'number', 'subtype' => ''];

	/** A text line column. */
	private const TEXT = ['type' => 'text', 'subtype' => 'line'];

	/**
	 * The integer json_decode() gives back for a whole float is the same number.
	 *
	 * @return void
	 */
	public function testAWholeNumberInAnotherTypeIsTheSame(): void {
		$this->assertTrue(RowReceiptComparison::same(self::NUMBER, 1.0, 1));
		$this->assertTrue(RowReceiptComparison::same(self::NUMBER, 42.0, 42));
		$this->assertTrue(RowReceiptComparison::same(self::NUMBER, 1.5, 1.5));
		$this->assertTrue(RowReceiptComparison::same(self::NUMBER, null, null));
		$this->assertTrue(RowReceiptComparison::same(self::TEXT, 'Some text', 'Some text'));
	}

	/**
	 * A value Tables really changed still differs: a rounded or emptied number, a number as text, a cut, trimmed or
	 * retyped text, another option, and an integer-valued float on a column that is not a plain number.
	 *
	 * @return void
	 */
	public function testARealChangeStillDiffers(): void {
		$this->assertFalse(RowReceiptComparison::same(self::NUMBER, 1.5, 2));
		$this->assertFalse(RowReceiptComparison::same(self::NUMBER, 1.25, 1.3));
		$this->assertFalse(RowReceiptComparison::same(self::NUMBER, 0.0, null));
		$this->assertFalse(RowReceiptComparison::same(self::NUMBER, null, 0));
		$this->assertFalse(RowReceiptComparison::same(self::NUMBER, 42.0, '42'));
		$this->assertFalse(RowReceiptComparison::same(self::TEXT, 'Some text', 'Some tex'));
		$this->assertFalse(RowReceiptComparison::same(self::TEXT, 'Some text', 'Some text '));
		$this->assertFalse(RowReceiptComparison::same(self::TEXT, '1', 1));
		$this->assertFalse(RowReceiptComparison::same(['type' => 'selection', 'subtype' => ''], '3', 3));
		$this->assertFalse(RowReceiptComparison::same(['type' => 'number', 'subtype' => 'stars'], 3, 3.0));
		$this->assertFalse(RowReceiptComparison::same([], 1.0, 1));
	}
}
