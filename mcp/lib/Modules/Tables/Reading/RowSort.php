<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

/**
 * The sort the engine gets: the user's rules (only visible columns and meta columns), or without them the saved sort of
 * the view or the table (2.2.2 on), deduplicated by column with the first occurrence kept, and the row id (-1) as the
 * last rule in ascending order unless a rule already sorts by it, in either direction: an explicit `-1 DESC` stays. The
 * engine's own extra id ordering of 2.3 does not change the direction asked.
 */
final class RowSort {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The effective sort.
	 *
	 * @param list<array{column_id:int, mode:string}> $user the user's rules
	 * @param array<int, true> $visible ids of the columns the user can see
	 * @param list<array{columnId:int, mode:string}> $saved the saved sort, already checked
	 * @return list<array{columnId:int, mode:string}> the sort
	 * @throws RowsRefusal when a user rule names a column the user cannot see
	 */
	public static function of(array $user, array $visible, array $saved): array {
		$rules = [];
		foreach ($user as $rule) {
			$id = $rule['column_id'];
			if (!isset($visible[$id]) && !in_array($id, RowFilterDomain::META_COLUMNS, true)) {
				throw RowsRefusal::invalidQuery();
			}
			$rules[] = ['columnId' => $id, 'mode' => $rule['mode']];
		}
		$rules = $rules === [] ? $saved : $rules;
		$seen = [];
		$effective = [];
		foreach ($rules as $rule) {
			if (!isset($seen[$rule['columnId']])) {
				$seen[$rule['columnId']] = true;
				$effective[] = $rule;
			}
		}
		if (!isset($seen[-1])) {
			$effective[] = ['columnId' => -1, 'mode' => 'ASC'];
		}
		return $effective;
	}
}
