<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\Tables\Discovery\TablesPage;
use OCA\Mcp\Tools\Tables\Handler\CreateRowHandler;
use OCA\Mcp\Tools\Tables\Handler\ListColumnsHandler;
use OCA\Mcp\Tools\Tables\Handler\ListRowsHandler;
use OCA\Mcp\Tools\Tables\Handler\ListTablesHandler;
use OCA\Mcp\Tools\Tables\Handler\ListViewsHandler;
use OCA\Mcp\Tools\Tables\Handler\UpdateRowHandler;
use OCA\Mcp\Tools\Tables\Rows\RowsMessages;
use OCA\Mcp\Tools\Tables\Rows\RowsRequest;
use OCA\Mcp\Tools\Tables\Write\RowWriteMessages;
use OCA\Mcp\Tools\Tables\Write\RowWritePlanRenderer;
use OCA\Mcp\Tools\Tables\Write\RowWriteRequest;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

/**
 * Nextcloud Tables (issue #1): the tables and views the user can open, the columns of one of them, and the rows of one of
 * them; with the separate `create` and `edit` grants, one row created or updated per call through the Tables app, after a
 * plan. `read` is granted by default like every read; `create` and `edit` start denied like every write. Its tools carry
 * the app id, so the registry hides them and refuses a call while Tables is not enabled for the user or is older than MCP
 * supports ({@see \OCA\Mcp\Service\Compat\AppEnablement::MINIMUM_VERSIONS}). Each tool has its own handler; the module
 * only describes the tools and delegates.
 */
final class TablesModule implements ToolModule, ToolGuideNotes, PreviewsWrites, RendersPlans {
	/** Grant module of the tools. */
	public const MODULE = 'tables';

	/**
	 * Receives the handlers.
	 *
	 * @param ListTablesHandler $tables backs tables_list_tables
	 * @param ListViewsHandler $views backs tables_list_views
	 * @param ListColumnsHandler $columns backs tables_list_columns
	 * @param ListRowsHandler $rows backs tables_list_rows
	 * @param CreateRowHandler $createRow backs tables_create_row
	 * @param UpdateRowHandler $updateRow backs tables_update_row
	 * @return void
	 */
	public function __construct(
		private ListTablesHandler $tables,
		private ListViewsHandler $views,
		private ListColumnsHandler $columns,
		private ListRowsHandler $rows,
		private CreateRowHandler $createRow,
		private UpdateRowHandler $updateRow,
	) {
	}

	/**
	 * Behaviour notes for the tool guide.
	 *
	 * @return list<string> short behaviour notes about this module
	 */
	public function guideNotes(): array {
		return [...TablesMessages::GUIDE_NOTES, RowsMessages::GUIDE_NOTE, RowWriteMessages::GUIDE_NOTE];
	}

	/**
	 * The tools of the module.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
	 */
	public function definitions(): array {
		return [
			self::definition(ListTablesHandler::TOOL, TablesMessages::TOOL_LIST_TABLES, self::page('tables')),
			self::definition(ListViewsHandler::TOOL, TablesMessages::TOOL_LIST_VIEWS, self::page('views')),
			self::definition(ListColumnsHandler::TOOL, TablesMessages::TOOL_LIST_COLUMNS, [
				'table_id' => ['type' => 'integer', 'minimum' => 1,
					'description' => 'Id of the table, as tables_list_tables returns it. Give this or view_id, not both.'],
				'view_id' => ['type' => 'integer', 'minimum' => 1,
					'description' => 'Id of the view, as tables_list_views returns it. Give this or table_id, not both.'],
			]),
			self::definition(ListRowsHandler::TOOL, RowsMessages::TOOL, RowsRequest::schema()),
			self::definition(CreateRowHandler::TOOL, RowWriteMessages::TOOL_CREATE, ...self::write('create', false)),
			self::definition(UpdateRowHandler::TOOL, RowWriteMessages::TOOL_UPDATE, ...self::write('edit', true)),
		];
	}

	/**
	 * Delegates a call to the handler of the tool.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments arguments already validated against the schema of the tool
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws InvalidArgumentException for a tool this module does not have, or both or neither of table_id and view_id
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the table or view is not found or Tables is not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return match ($name) {
			ListTablesHandler::TOOL => $this->tables->handle($arguments, $userId),
			ListViewsHandler::TOOL => $this->views->handle($arguments, $userId),
			ListColumnsHandler::TOOL => $this->columns->handle($arguments, $userId),
			ListRowsHandler::TOOL => $this->rows->handle($arguments, $userId),
			CreateRowHandler::TOOL => $this->createRow->handle($arguments, $userId),
			UpdateRowHandler::TOOL => $this->updateRow->handle($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

	/**
	 * The plan of a write tool, without writing anything.
	 *
	 * @param string $name tool name of a writing definition
	 * @param array<string, mixed> $arguments arguments already validated against the schema of the tool
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws InvalidArgumentException for a tool this module does not write with, or malformed arguments
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the write is refused or Tables is not available
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return match ($name) {
			CreateRowHandler::TOOL => $this->createRow->preview($arguments, $userId),
			UpdateRowHandler::TOOL => $this->updateRow->preview($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

	/**
	 * The readable body of a plan.
	 *
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan returned by preview()
	 * @return string|null Markdown body, or null for the generic one
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return in_array($tool, [CreateRowHandler::TOOL, UpdateRowHandler::TOOL], true) ? (new RowWritePlanRenderer())->render($plan) : null;
	}

	/**
	 * One tool of the module.
	 *
	 * @param string $name tool name
	 * @param string $description tool description
	 * @param array<string, mixed> $properties its arguments
	 * @param string $operation its grant operation
	 * @param list<string> $required the arguments it needs
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
	 */
	private static function definition(string $name, string $description, array $properties, string $operation = 'read', array $required = []): array {
		return [
			'name' => $name,
			'description' => $description,
			'module' => self::MODULE,
			'operation' => $operation,
			'app' => TablesServices::APP_ID,
			'inputSchema' => [
				'type' => 'object',
				'properties' => $properties,
				'additionalProperties' => false,
			] + ($required === [] ? [] : ['required' => $required]),
		];
	}

	/**
	 * The arguments, operation and required arguments of a row write tool.
	 *
	 * @param string $operation its grant operation
	 * @param bool $update whether it updates a row
	 * @return array{0: array<string, mixed>, 1: string, 2: list<string>} properties, operation and required arguments
	 */
	private static function write(string $operation, bool $update): array {
		$schema = RowWriteRequest::schema($update);
		return [$schema['properties'], $operation, $schema['required']];
	}

	/**
	 * The paging arguments of a listing.
	 *
	 * @param string $what what is listed, for the descriptions
	 * @return array<string, mixed> limit and offset
	 */
	private static function page(string $what): array {
		return [
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => TablesPage::MAX_LIMIT, 'default' => TablesPage::DEFAULT_LIMIT,
				'description' => sprintf('Maximum number of %s returned (default %d, at most %d).', $what, TablesPage::DEFAULT_LIMIT, TablesPage::MAX_LIMIT)],
			'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0,
				'description' => sprintf('Number of %s to skip, from nextOffset of the previous page (default 0).', $what)],
		];
	}
}
