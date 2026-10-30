<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Handler;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Tables\Columns\ColumnLookup;
use OCA\Mcp\Tools\Tables\Columns\ColumnShape;
use OCA\Mcp\Tools\Tables\Links\TablesLinks;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/**
 * Backs `tables_list_columns`: the columns of one table or one view the user can open ({@see ColumnLookup}), shaped by
 * {@see ColumnShape}. All of them: a table has few columns, and the Tables app returns them in one call.
 */
final class ListColumnsHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tables_list_columns';

	/**
	 * Receives the lookup and the links.
	 *
	 * @param ColumnLookup $columns the columns of a table or view
	 * @param TablesLinks $links direct links into the Tables app
	 * @return void
	 */
	public function __construct(
		private ColumnLookup $columns,
		private TablesLinks $links,
	) {
	}

	/**
	 * Lists the columns.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: table_id or view_id
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException when both or neither of table_id and view_id are given
	 * @throws ToolFailure when the table or view is not the user's to open, or the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$tableId = isset($arguments['table_id']) ? (int)$arguments['table_id'] : null;
		$viewId = isset($arguments['view_id']) ? (int)$arguments['view_id'] : null;
		if (($tableId === null) === ($viewId === null)) {
			throw new ArgumentValidationException('Invalid arguments', '', TablesMessages::oneOfTableOrView());
		}
		$columns = $viewId !== null ? $this->columns->forView($userId, $viewId) : $this->columns->forTable($userId, (int)$tableId);
		return ToolResult::json(($viewId !== null
			? ['viewId' => $viewId, 'url' => $this->links->view($viewId)]
			: ['tableId' => $tableId, 'url' => $this->links->table((int)$tableId)]) + [
				'columns' => array_map(ColumnShape::of(...), $columns),
				'notice' => TablesMessages::UNTRUSTED_NOTICE,
			]);
	}
}
