<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\PlanState;

/**
 * The state a row write was planned on, bound by its {@see PlanState}: the arguments that change the effect (target, row,
 * cells as the parser of the installed release will see them), the Tables version, every accepted column whole as
 * Column::jsonSerialize() gives it (limits, uniqueness, allowed patterns, mandatory flag, options, defaults, user/group
 * settings, custom settings), the view's settings, the view's saved filter and sort as stored with the columns they name,
 * hidden ones included, the hidden values the enhancer will add as resolved now, what the relation gate found, whether the
 * table is the caller's, the notes shown and, for an update, the row's values whole and its last edit time.
 * It is its own fingerprint: the digest of tables_list_rows is not reused or changed. Hidden parts only enter the HMAC.
 */
final class RowWriteState {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * What the plan state of a write binds.
	 *
	 * @param RowWritePrepared $prepared the checked write
	 * @param list<string> $notes the keys of the notes the plan shows
	 * @return array<string, mixed> the bound state
	 */
	public static function shown(RowWritePrepared $prepared, array $notes): array {
		$target = $prepared->target;
		$view = $target->view;
		return [
			'target' => [$target->type, $target->id, $target->tableId],
			'row' => $prepared->request->rowId,
			'cells' => array_map(static fn (array $cell): array => [$cell['columnId'], $cell['effect']], $prepared->cells),
			'tables' => $target->version,
			'columns' => $target->columns,
			'settings' => $target->settings,
			'viewColumns' => $view === null ? null : array_map('intval', (array)$view->getColumnIds()),
			'savedRules' => $view === null ? null : [$view->getFilter(), $view->getSort()],
			'referenced' => $prepared->referenced,
			'defaults' => $prepared->defaults,
			'relations' => $prepared->relations,
			'owned' => $target->ownedByCaller,
			'notes' => $notes,
			'current' => $prepared->current,
		];
	}
}
