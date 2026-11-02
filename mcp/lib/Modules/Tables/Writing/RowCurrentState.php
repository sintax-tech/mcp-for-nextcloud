<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Reading\RowCondition;
use OCA\Mcp\Modules\Tables\Reading\RowFilterComposer;
use OCA\Mcp\Modules\Tables\Reading\SavedViewRules;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Modules\Tables\Integration\TablesCalls;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The row an update would change, read with the semantics of tables_list_rows: Row2Mapper::findAll() on the accepted
 * columns, the view's saved filter (checked by {@see SavedViewRules}) and the condition id = row, with the caller's id.
 * It proves the row exists, belongs to the table and is in the view; a row outside any of them reads like "not found".
 * The values are kept whole, as Row2::jsonSerialize() gives them, for the plan state; only the plan shortens what it shows.
 */
class RowCurrentState {
	/**
	 * Receives the Tables services and the session guard.
	 *
	 * @param TablesServices $services resolver of the Tables services
	 * @param TablesSessionGuard $session checks the session is the caller's
	 * @return void
	 */
	public function __construct(
		private TablesServices $services,
		private TablesSessionGuard $session,
	) {
	}

	/**
	 * The row as it is now.
	 *
	 * @param RowWriteTarget $target the target of the update
	 * @param int $rowId the row
	 * @param string $userId authenticated user UID
	 * @return array{values: array<int, mixed>, lastEditAt: string} the values of the accepted columns, by column id, and the
	 *   row's last edit time
	 * @throws ToolFailure when the row is not in the target, or the saved filter cannot be applied
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function read(RowWriteTarget $target, int $rowId, string $userId): array {
		$factors = [];
		if ($target->view !== null) {
			$view = $target->view;
			$rules = new SavedViewRules($this->services->columnMapper($userId), $target->tableId);
			$factors[] = $rules->filter(is_string($view->getFilter()) && $view->getFilter() !== '' ? $view->getFilter() : null,
				static fn (): array => (array)$view->getFilterArray());
		}
		$factors[] = RowCondition::of([[['columnId' => -1, 'operator' => 'is-equal', 'value' => (string)$rowId]]]);
		$condition = RowFilterComposer::compose($factors);
		$columnIds = array_keys($target->columns);
		if ($condition->isFalse() || $columnIds === []) {
			throw new ToolFailure(TablesMessages::notFound());
		}
		$this->session->assertCaller($userId);
		$mapper = $this->services->rowMapper($userId);
		$tableId = $target->tableId;
		$filter = $condition->branches();
		$rows = TablesCalls::run(static fn (): array => array_values((array)$mapper->findAll($columnIds, $tableId, 2, 0, $filter, null, $userId)));
		$found = null;
		foreach ($rows as $row) {
			$data = (array)$row->jsonSerialize();
			if ((int)($data['id'] ?? 0) === $rowId && (int)($data['tableId'] ?? $tableId) === $tableId) {
				$found = $data;
			}
		}
		if ($found === null || count($rows) !== 1) {
			throw new ToolFailure(TablesMessages::notFound());
		}
		$values = [];
		foreach ((array)($found['data'] ?? []) as $cell) {
			$columnId = is_array($cell) ? (int)($cell['columnId'] ?? 0) : 0;
			if (isset($target->columns[$columnId])) {
				$values[$columnId] = $cell['value'] ?? null;
			}
		}
		return ['values' => $values, 'lastEditAt' => (string)($found['lastEditAt'] ?? '')];
	}
}
