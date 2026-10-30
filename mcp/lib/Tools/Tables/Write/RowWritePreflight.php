<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use OCA\Mcp\Tools\Tables\Rows\TablesSessionGuard;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Everything a row write checks before the Tables app is asked to write, in this order, the same for the plan and for
 * the confirmed call:
 * 1. the session is the caller's ({@see TablesSessionGuard});
 * 2. the target under the native write permission, with its accepted columns ({@see RowWriteColumns});
 * 3. each cell on an accepted, writable column, its value in the column's domain ({@see CellValueDomain}), and the
 *    mandatory columns;
 * 4. each relation cell's target readable for the caller ({@see RelationWriteGate}), before any native parser sees it;
 * 5. for a creation through a view, the hidden values the native enhancer will add ({@see ViewDefaultsGate});
 * 6. for an update, the row as it is now ({@see RowCurrentState}).
 * A refusal anywhere here writes nothing, and says so.
 */
class RowWritePreflight {
	/**
	 * Receives the checks.
	 *
	 * @param TablesSessionGuard $session checks the session is the caller's
	 * @param RowWriteColumns $columns the target and its columns
	 * @param CellValueDomain $domain the values
	 * @param RelationWriteGate $relations the relation targets
	 * @param ViewDefaultsGate $defaults the hidden values of a view
	 * @param RowCurrentState $current the row of an update
	 * @return void
	 */
	public function __construct(
		private TablesSessionGuard $session,
		private RowWriteColumns $columns,
		private CellValueDomain $domain,
		private RelationWriteGate $relations,
		private ViewDefaultsGate $defaults,
		private RowCurrentState $current,
	) {
	}

	/**
	 * The checked write.
	 *
	 * @param RowWriteRequest $request the write
	 * @param string $userId authenticated user UID
	 * @return RowWritePrepared what the plan and the write use
	 * @throws ToolFailure when anything is refused; nothing was written
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function prepare(RowWriteRequest $request, string $userId): RowWritePrepared {
		try {
			$this->session->assertCaller($userId);
		} catch (RowsRefusal) {
			throw RowWriteRefusal::session();
		}
		$target = $this->columns->target($request, $userId);
		$cells = [];
		$relations = [];
		foreach ($request->cells as $columnId => $value) {
			$column = $target->columns[$columnId] ?? null;
			if ($column === null || $target->isReadonly($columnId)) {
				throw RowWriteRefusal::column();
			}
			if (($column['type'] ?? '') === 'relation') {
				$relations[$columnId] = $this->relations->check($column, $userId, $target->version);
			}
			$forms = $this->domain->cell($column, $value, $target->version, $userId);
			if ($target->isMandatory($columnId) && CellValueDomain::isEmpty($column, $forms['effect'])) {
				throw RowWriteRefusal::mandatory();
			}
			$cells[] = ['columnId' => $columnId, 'argument' => $forms['argument'], 'effect' => $forms['effect']];
		}
		if ($request->rowId === null) {
			foreach (array_keys($target->columns) as $columnId) {
				if ($target->isMandatory($columnId) && !array_key_exists($columnId, $request->cells)) {
					throw RowWriteRefusal::mandatory();
				}
			}
		}
		$hidden = $request->rowId === null && $target->view !== null
			? $this->defaults->resolve($target, array_keys($request->cells), $userId)
			: ['defaults' => [], 'referenced' => []];
		if ($request->rowId !== null && $target->view !== null) {
			$hidden['referenced'] = $this->defaults->referenced($target, $userId);
		}
		$current = $request->rowId !== null ? $this->current->read($target, $request->rowId, $userId) : null;
		return new RowWritePrepared($request, $target, $cells, $hidden['defaults'], $hidden['referenced'], $relations, $current);
	}
}
