<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

/**
 * The conjunction of the saved filter of the view (V), the user's filter (U) and the search (S), distributed into the one
 * disjunction of conjunctions the Tables engine reads: every group is Vi ∧ Uj ∧ Sk, so no group is ever less restrictive
 * than the view. The size is computed from the factors before anything is multiplied, and a query over the limits is
 * refused, never cut: groups = |V|·|U|·|S| and conditions = Σ cond(F)·Π branches(others), TRUE being one branch of zero
 * conditions and FALSE making the whole conjunction FALSE.
 */
final class RowFilterComposer {
	/** Most groups of the composed filter. */
	public const MAX_GROUPS = 64;

	/** Most conditions of the composed filter, all groups together. */
	public const MAX_CONDITIONS = 512;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The conjunction of the factors.
	 *
	 * @param list<RowCondition> $factors the factors, in the order V, U, S
	 * @return RowCondition TRUE, FALSE or the distributed groups
	 * @throws RowsRefusal when the composed filter would exceed the limits
	 */
	public static function compose(array $factors): RowCondition {
		foreach ($factors as $factor) {
			if ($factor->isFalse()) {
				return RowCondition::false();
			}
		}
		$factors = array_values(array_filter($factors, static fn (RowCondition $factor): bool => !$factor->isTrue()));
		if ($factors === []) {
			return RowCondition::true();
		}
		[$groups, $conditions] = self::size($factors);
		if ($groups > self::MAX_GROUPS || $conditions > self::MAX_CONDITIONS) {
			throw RowsRefusal::tooLarge();
		}
		$product = [[]];
		foreach ($factors as $factor) {
			$next = [];
			foreach ($product as $prefix) {
				foreach ($factor->branches() as $branch) {
					$next[] = [...$prefix, ...$branch];
				}
			}
			$product = $next;
		}
		return RowCondition::of($product);
	}

	/**
	 * The groups and conditions the composition would make, without making it.
	 *
	 * @param list<RowCondition> $factors the factors that are neither TRUE nor FALSE
	 * @return array{0:int, 1:int} groups and conditions
	 */
	public static function size(array $factors): array {
		$branches = array_map(static fn (RowCondition $factor): int => count($factor->branches()), $factors);
		$groups = (int)array_product($branches);
		$conditions = 0;
		foreach ($factors as $i => $factor) {
			$others = $branches;
			unset($others[$i]);
			$conditions += $factor->conditionCount() * (int)array_product($others);
		}
		return [$groups, $conditions];
	}
}
