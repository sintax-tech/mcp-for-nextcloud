<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tools\Tables\Rows\RowSort;
use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use PHPUnit\Framework\TestCase;

/** The effective sort: user rules on visible columns, else the saved sort, deduplicated, and the row id as total order. */
final class RowSortTest extends TestCase {
	/** Visible columns. */
	private const VISIBLE = [11 => true, 12 => true];

	/**
	 * The id is appended ascending unless a rule already sorts by it; an explicit id DESC stays DESC and is not repeated.
	 *
	 * @return void
	 */
	public function testTheIdIsTheTotalOrder(): void {
		$this->assertSame([['columnId' => 12, 'mode' => 'DESC'], ['columnId' => -1, 'mode' => 'ASC']], RowSort::of([['column_id' => 12, 'mode' => 'DESC']], self::VISIBLE, []));
		$this->assertSame([['columnId' => -1, 'mode' => 'DESC']], RowSort::of([['column_id' => -1, 'mode' => 'DESC']], self::VISIBLE, []));
		$this->assertSame([['columnId' => 12, 'mode' => 'ASC'], ['columnId' => -1, 'mode' => 'DESC']],
			RowSort::of([['column_id' => 12, 'mode' => 'ASC'], ['column_id' => -1, 'mode' => 'DESC']], self::VISIBLE, []));
		$this->assertSame([['columnId' => -1, 'mode' => 'ASC']], RowSort::of([], self::VISIBLE, []));
	}

	/**
	 * Without user rules the saved sort applies, hidden columns included, deduplicated with the first one kept.
	 *
	 * @return void
	 */
	public function testTheSavedSortIsTheDefault(): void {
		$saved = [['columnId' => 14, 'mode' => 'DESC'], ['columnId' => 14, 'mode' => 'ASC'], ['columnId' => 12, 'mode' => 'ASC']];
		$this->assertSame([['columnId' => 14, 'mode' => 'DESC'], ['columnId' => 12, 'mode' => 'ASC'], ['columnId' => -1, 'mode' => 'ASC']], RowSort::of([], self::VISIBLE, $saved));
		$this->assertSame([['columnId' => 11, 'mode' => 'ASC'], ['columnId' => -1, 'mode' => 'ASC']], RowSort::of([['column_id' => 11, 'mode' => 'ASC']], self::VISIBLE, $saved));
	}

	/**
	 * A user rule on a hidden or foreign column is refused.
	 *
	 * @return void
	 */
	public function testAHiddenColumnIsRefused(): void {
		$this->expectException(RowsRefusal::class);
		RowSort::of([['column_id' => 14, 'mode' => 'ASC']], self::VISIBLE, []);
	}
}
