<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

/**
 * One factor of the row filter, in the shape the Tables engine reads (a list of groups OR-ed, conditions AND-ed inside a
 * group), with the two cases the engine cannot tell apart kept apart: TRUE (no restriction, one empty branch) and FALSE
 * (nothing can match, no branch). The engine reads an empty filter as no restriction, so FALSE never reaches it.
 */
final class RowCondition {
	/**
	 * Receives the branches.
	 *
	 * @param list<list<array{columnId:int, operator:string, value:string|array<mixed>}>>|null $groups the branches, null for TRUE
	 * @return void
	 */
	private function __construct(
		private ?array $groups,
	) {
	}

	/**
	 * No restriction.
	 *
	 * @return self TRUE
	 */
	public static function true(): self {
		return new self(null);
	}

	/**
	 * Nothing can match.
	 *
	 * @return self FALSE
	 */
	public static function false(): self {
		return new self([]);
	}

	/**
	 * A disjunction of conjunctions; every group must hold at least one condition.
	 *
	 * @param list<list<array{columnId:int, operator:string, value:string|array<mixed>}>> $groups the groups, at least one
	 * @return self the condition
	 * @throws \InvalidArgumentException for no group or an empty group, which would read as no restriction
	 */
	public static function of(array $groups): self {
		if ($groups === []) {
			throw new \InvalidArgumentException('A condition needs at least one group');
		}
		foreach ($groups as $group) {
			if ($group === []) {
				throw new \InvalidArgumentException('A group needs at least one condition');
			}
		}
		return new self(array_values(array_map('array_values', $groups)));
	}

	/**
	 * Whether this is TRUE.
	 *
	 * @return bool true for no restriction
	 */
	public function isTrue(): bool {
		return $this->groups === null;
	}

	/**
	 * Whether this is FALSE.
	 *
	 * @return bool true when nothing can match
	 */
	public function isFalse(): bool {
		return $this->groups === [];
	}

	/**
	 * The branches: TRUE is one empty branch, FALSE none.
	 *
	 * @return list<list<array{columnId:int, operator:string, value:string|array<mixed>}>> the branches
	 */
	public function branches(): array {
		return $this->groups ?? [[]];
	}

	/**
	 * The number of conditions of all branches; TRUE counts zero.
	 *
	 * @return int the conditions
	 */
	public function conditionCount(): int {
		return array_sum(array_map('count', $this->branches()));
	}
}
