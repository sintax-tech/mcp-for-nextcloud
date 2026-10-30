<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Handler;

use OCA\Mcp\Tools\Tables\Discovery\DiscoveryShapes;
use OCA\Mcp\Tools\Tables\Discovery\TablesDiscovery;
use OCA\Mcp\Tools\Tables\Discovery\TablesPage;
use OCA\Mcp\Tools\Tables\Discovery\ViewsDiscovery;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/**
 * Backs `tables_list_views`: one page of the views the user can open ({@see ViewsDiscovery}), in table then view order.
 * The views of a table the user can read but not manage are not listed, as in the Tables app; the answer counts those
 * tables, so the limit is never silent.
 */
final class ListViewsHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tables_list_views';

	/**
	 * Receives the discoveries and the shapes.
	 *
	 * @param TablesDiscovery $tables the tables the user can open
	 * @param ViewsDiscovery $views the views the user can open
	 * @param DiscoveryShapes $shapes what a view looks like in the answer
	 * @return void
	 */
	public function __construct(
		private TablesDiscovery $tables,
		private ViewsDiscovery $views,
		private DiscoveryShapes $shapes,
	) {
	}

	/**
	 * Lists one page of views.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: limit, offset
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$tables = $this->tables->tables($userId);
		$page = TablesPage::of($this->views->views($userId, $tables), (int)($arguments['limit'] ?? TablesPage::DEFAULT_LIMIT), (int)($arguments['offset'] ?? 0));
		return ToolResult::json([
			'views' => array_map(fn (array $entry): array => $this->shapes->view($entry['view'], $entry['via']), $page['items']),
			'total' => $page['total'],
			'hasMore' => $page['hasMore'],
			'nextOffset' => $page['nextOffset'],
			'tablesWithoutListedViews' => count(array_filter($tables, static fn (array $entry): bool => !$entry['manages'])),
			'notice' => TablesMessages::UNTRUSTED_NOTICE,
		]);
	}
}
