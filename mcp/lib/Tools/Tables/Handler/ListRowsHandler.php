<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Handler;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tools\Tables\Links\TablesLinks;
use OCA\Mcp\Tools\Tables\Rows\RowReader;
use OCA\Mcp\Tools\Tables\Rows\RowsRequest;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * Backs `tables_list_rows`: one page of the rows of a table or a view the user can read ({@see RowReader}).
 */
final class ListRowsHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tables_list_rows';

	/**
	 * Receives the reader and the links.
	 *
	 * @param RowReader $rows the row reader
	 * @param TablesLinks $links direct links into the Tables app
	 * @return void
	 */
	public function __construct(
		private RowReader $rows,
		private TablesLinks $links,
	) {
	}

	/**
	 * Lists one page of rows.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException for both or neither target, an empty filter or group, or a repeated sort column
	 * @throws ToolFailure when the target is not the user's to read, the query is refused or the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$request = RowsRequest::of($arguments);
		$page = $this->rows->page($request, $userId);
		$target = $request->targetType === 'view'
			? ['viewId' => $request->targetId, 'url' => $this->links->view($request->targetId)]
			: ['tableId' => $request->targetId, 'url' => $this->links->table($request->targetId)];
		return ToolResult::json($target + $page + ['notice' => TablesMessages::UNTRUSTED_NOTICE]);
	}
}
