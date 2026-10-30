<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tools\Tables\Rows\RowCondition;
use OCA\Mcp\Tools\Tables\Rows\RowFilterComposer;
use OCA\Mcp\Tools\Tables\Rows\RowsMessages;
use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use PHPUnit\Framework\TestCase;

/**
 * V ∧ U ∧ S: TRUE is identity and counts zero conditions, FALSE wins, every composed group holds one group of each factor,
 * and the size is checked before the product: 64 groups and 512 conditions pass, one condition more is refused.
 */
final class RowFilterComposerTest extends TestCase {
	/**
	 * TRUE everywhere is TRUE, any FALSE is FALSE, TRUE leaves the other factor as it is.
	 *
	 * @return void
	 */
	public function testIdentityAndFalse(): void {
		$v = self::factor('v', 2, 1);
		$this->assertTrue(RowFilterComposer::compose([RowCondition::true(), RowCondition::true(), RowCondition::true()])->isTrue());
		$this->assertTrue(RowFilterComposer::compose([$v, RowCondition::false(), RowCondition::true()])->isFalse());
		$this->assertSame($v->branches(), RowFilterComposer::compose([RowCondition::true(), $v, RowCondition::true()])->branches());
		$this->assertSame(0, RowCondition::true()->conditionCount());
		$this->assertSame([[]], RowCondition::true()->branches());
		$this->assertSame([], RowCondition::false()->branches());
	}

	/**
	 * V2 × U2 × S2 gives 8 groups, each with one V group, one U group and one S group.
	 *
	 * @return void
	 */
	public function testEveryGroupHoldsEachFactor(): void {
		$composed = RowFilterComposer::compose([self::factor('v', 2, 1), self::factor('u', 2, 1), self::factor('s', 2, 1)]);
		$this->assertCount(8, $composed->branches());
		foreach ($composed->branches() as $group) {
			$this->assertSame(['s', 'u', 'v'], self::sources($group));
		}
	}

	/**
	 * V 8×4 and U 8×4 without search: 64 groups and 512 conditions are accepted; one condition more is refused before
	 * the product.
	 *
	 * @return void
	 */
	public function testTheBoundaries(): void {
		$v = self::factor('v', 8, 4);
		$u = self::factor('u', 8, 4);
		$this->assertSame([64, 512], RowFilterComposer::size([$v, $u]));
		$composed = RowFilterComposer::compose([$v, $u, RowCondition::true()]);
		$this->assertCount(64, $composed->branches());
		$this->assertSame(512, $composed->conditionCount());

		$wider = RowCondition::of([...array_slice($u->branches(), 0, 7), [...$u->branches()[7], ['columnId' => 99, 'operator' => 'is-equal', 'value' => 'u']]]);
		$this->assertSame([64, 520], RowFilterComposer::size([$v, $wider]));
		try {
			RowFilterComposer::compose([$v, $wider, RowCondition::true()]);
			$this->fail('expected the composed filter to be refused');
		} catch (RowsRefusal $e) {
			$this->assertSame(RowsMessages::tooLarge(), $e->getMessage());
		}
		$this->expectException(RowsRefusal::class);
		RowFilterComposer::compose([self::factor('v', 9, 1), self::factor('u', 8, 1)]);
	}

	/**
	 * No group, or an empty group, is not a condition: it would read as no restriction.
	 *
	 * @return void
	 */
	public function testEmptyGroupsAreNoCondition(): void {
		foreach ([[], [[]], [[['columnId' => 1, 'operator' => 'is-empty', 'value' => '']], []]] as $groups) {
			try {
				RowCondition::of($groups);
				$this->fail('expected an invalid condition');
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Groups of conditions tagged with their source.
	 *
	 * @param string $source tag
	 * @param int $groups number of groups
	 * @param int $conditions conditions per group
	 * @return RowCondition the condition
	 */
	private static function factor(string $source, int $groups, int $conditions): RowCondition {
		return RowCondition::of(array_map(static fn (int $g): array => array_map(static fn (int $c): array => ['columnId' => $g * 10 + $c, 'operator' => 'is-equal', 'value' => $source], range(1, $conditions)), range(1, $groups)));
	}

	/**
	 * The sources of a composed group.
	 *
	 * @param list<array{columnId:int, operator:string, value:string}> $group the group
	 * @return list<string> sorted sources, once each
	 */
	private static function sources(array $group): array {
		$sources = array_values(array_unique(array_column($group, 'value')));
		sort($sources);
		return $sources;
	}
}
