<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

use OCA\Mcp\Modules\Tables\Reading\DiscoveryShapes;
use OCA\Mcp\Modules\Tables\Reading\TablesDiscovery;
use OCA\Mcp\Modules\Tables\Reading\TablesPage;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * Backs `tables_list_tables`: one page of the tables the user can open ({@see TablesDiscovery}), in id order.
 */
final class ListTablesHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tables_list_tables';

	/**
	 * Receives the discovery and the shapes.
	 *
	 * @param TablesDiscovery $tables the tables the user can open
	 * @param DiscoveryShapes $shapes what a table looks like in the answer
	 * @return void
	 */
	public function __construct(
		private TablesDiscovery $tables,
		private DiscoveryShapes $shapes,
	) {
	}

	/**
	 * Lists one page of tables.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: limit, offset
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$page = TablesPage::of($this->tables->tables($userId), (int)($arguments['limit'] ?? TablesPage::DEFAULT_LIMIT), (int)($arguments['offset'] ?? 0));
		return ToolResult::json([
			'tables' => array_map(fn (array $entry): array => $this->shapes->table($entry['table'], $entry['manages'], $userId), $page['items']),
			'total' => $page['total'],
			'hasMore' => $page['hasMore'],
			'nextOffset' => $page['nextOffset'],
			'notice' => TablesMessages::UNTRUSTED_NOTICE,
		]);
	}
}
