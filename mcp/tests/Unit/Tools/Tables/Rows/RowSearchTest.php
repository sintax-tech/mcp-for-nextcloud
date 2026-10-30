<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tools\Tables\Rows\RowSearch;
use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use PHPUnit\Framework\TestCase;

/**
 * The search becomes native conditions on visible columns only: contains on text, and selection labels translated to
 * option ids (is-equal on a single selection, contains on a multiple one), NFC and lower case; no candidate is FALSE.
 */
final class RowSearchTest extends TestCase {
	/** Visible columns: a text, a single selection with 1, 2, 10 and a multiple selection with 1, 10. */
	private const PROJECTION = [
		['id' => 11, 'type' => 'text', 'subtype' => 'line'],
		['id' => 13, 'type' => 'selection', 'subtype' => '', 'selectionOptions' => [['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed'], ['id' => 10, 'label' => "A\u{0301}rvore"]]],
		['id' => 15, 'type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => [['id' => 1, 'label' => 'red'], ['id' => 10, 'label' => 'Blue']]],
		['id' => 12, 'type' => 'number', 'subtype' => ''],
		['id' => 17, 'type' => 'selection', 'subtype' => 'check'],
	];

	/**
	 * A label becomes its option id, as a whole id: "blue" finds 10 in the multiple selection, never 1.
	 *
	 * @return void
	 */
	public function testLabelsBecomeIds(): void {
		$this->assertSame([
			[['columnId' => 11, 'operator' => 'contains', 'value' => 'blue']],
			[['columnId' => 15, 'operator' => 'contains', 'value' => '10']],
		], RowSearch::of('blue', self::PROJECTION)->branches());
		$this->assertSame([
			[['columnId' => 11, 'operator' => 'contains', 'value' => 'ÁRV']],
			[['columnId' => 13, 'operator' => 'is-equal', 'value' => '10']],
		], RowSearch::of('ÁRV', self::PROJECTION)->branches(), 'decomposed and composed accents and case match');
	}

	/**
	 * Without a text column and without a matching label the search is FALSE; without a search it is TRUE; a hidden
	 * column is never searched because only the projection is.
	 *
	 * @return void
	 */
	public function testNoCandidateIsFalse(): void {
		$selectionsOnly = array_values(array_filter(self::PROJECTION, static fn (array $c): bool => $c['type'] !== 'text'));
		$this->assertTrue(RowSearch::of('nothing', $selectionsOnly)->isFalse());
		$this->assertTrue(RowSearch::of(null, self::PROJECTION)->isTrue());
		$this->assertSame([11], array_unique(array_column(array_merge(...RowSearch::of('needle', self::PROJECTION)->branches()), 'columnId')));
	}

	/**
	 * A search expanding into more than 32 conditions is refused.
	 *
	 * @return void
	 */
	public function testTheExpansionLimit(): void {
		$options = array_map(static fn (int $i): array => ['id' => $i, 'label' => "same $i"], range(1, 40));
		$this->expectException(RowsRefusal::class);
		RowSearch::of('same', [['id' => 13, 'type' => 'selection', 'subtype' => '', 'selectionOptions' => $options]]);
	}
}
