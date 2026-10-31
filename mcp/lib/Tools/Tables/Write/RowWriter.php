<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use OCA\Mcp\Tools\Tables\Rows\TablesSessionGuard;
use OCA\Mcp\Tools\Tables\TablesServices;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The one call that writes: RowService::create() or updateSet(), in the form the Tables controllers use, right after the
 * session is checked again and with the service resolved after that check. RowService validates and writes inside that
 * same call, without a transaction, and its events and listeners run after the write; so any exception out of it,
 * whatever its class, is an uncertain result, never "nothing written", and nothing is retried, undone or searched for.
 * The receipt holds only the cells this tool sent, each with the value asked and the value the service returned for that
 * column: it is the service's answer, not a new read of the row.
 */
class RowWriter {
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
	 * Writes the row.
	 *
	 * @param RowWritePrepared $prepared the checked write
	 * @param string $userId authenticated user UID
	 * @return array{rowId:int, cells:list<array{columnId:int, requested:mixed, returned:mixed}>, differs:bool} what the service
	 *   returned
	 * @throws RowWriteRefusal when the session is not the caller's; nothing was written
	 * @throws ToolFailure when the service did not confirm the write; it may or may not have happened
	 * @throws TablesUnavailableException when Tables is not available for the user; nothing was written
	 */
	public function write(RowWritePrepared $prepared, string $userId): array {
		try {
			$this->session->assertCaller($userId);
		} catch (RowsRefusal) {
			throw RowWriteRefusal::session();
		}
		$service = $this->services->rowService($userId);
		$target = $prepared->target;
		$data = array_map(static fn (array $cell): array => ['columnId' => $cell['columnId'], 'value' => $cell['argument']], $prepared->cells);
		$rowId = $prepared->request->rowId;
		$viewId = $target->type === 'view' ? $target->id : null;
		$tableId = $target->type === 'table' ? $target->id : null;
		try {
			$row = $rowId === null
				? $service->create($tableId, $viewId, $data)
				: $service->updateSet($rowId, $viewId, $data, $userId, $tableId);
			$returned = (array)$row->jsonSerialize();
		} catch (\Throwable $e) {
			throw new ToolFailure($rowId === null ? RowWriteMessages::createUncertain() : RowWriteMessages::updateUncertain($rowId), 0, $e);
		}
		$values = [];
		foreach ((array)($returned['data'] ?? []) as $cell) {
			if (is_array($cell) && isset($cell['columnId'])) {
				$values[(int)$cell['columnId']] = $cell['value'] ?? null;
			}
		}
		$cells = [];
		$differs = false;
		foreach ($prepared->cells as $cell) {
			$value = $values[$cell['columnId']] ?? null;
			$differs = $differs || $value !== $cell['effect'];
			$cells[] = ['columnId' => $cell['columnId'], 'requested' => $cell['effect'], 'returned' => $value];
		}
		return ['rowId' => (int)($returned['id'] ?? $rowId ?? 0), 'cells' => $cells, 'differs' => $differs];
	}
}
