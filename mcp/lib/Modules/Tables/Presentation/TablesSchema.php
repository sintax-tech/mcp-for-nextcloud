<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\Reading\TablesPage;
use OCA\Mcp\Modules\Tables\Writing\RowWriteRequest;

/** Shared published schemas of the Tables tools. */
final class TablesSchema {
	/** Prevents construction of this static schema catalog. */
	private function __construct() {
	}

	/**
	 * @param string $name published tool name
	 * @param string $description published description
	 * @param array<string, mixed> $properties ordered argument properties
	 * @param string $operation grant operation
	 * @param list<string> $required required argument names
	 * @param bool $destructive whether this new operation replaces or revokes state
	 * @param list<string> $requires lazy installation capabilities
	 * @return ToolDefinition the closed Tables definition
	 */
	public static function definition(string $name, string $description, array $properties, string $operation = 'read', array $required = [], bool $destructive = false, array $requires = []): ToolDefinition {
		$schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
		return new ToolDefinition(name: $name, module: TablesModule::MODULE, operation: $operation, description: $description,
			inputSchema: $schema + ($required === [] ? [] : ['required' => $required]), app: TablesServices::APP_ID, destructiveHint: $destructive, requires: $requires);
	}

	/**
	 * @param bool $update whether this writes an existing row
	 * @return ToolDefinition the row write definition with its required arguments
	 */
	public static function write(bool $update): ToolDefinition {
		$schema = RowWriteRequest::schema($update);
		return self::definition($update ? 'tables_update_row' : 'tables_create_row',
			$update ? RowWriteMessages::TOOL_UPDATE : RowWriteMessages::TOOL_CREATE,
			$schema['properties'], $update ? 'edit' : 'create', $schema['required']);
	}

	/** @return array<string, mixed> the mutually exclusive table and view identifiers */
	public static function columns(): array {
		return [
			'table_id' => ['type' => 'integer', 'minimum' => 1,
				'description' => 'Id of the table, as tables_list_tables returns it. Give this or view_id, not both.'],
			'view_id' => ['type' => 'integer', 'minimum' => 1,
				'description' => 'Id of the view, as tables_list_views returns it. Give this or table_id, not both.'],
		];
	}

	/**
	 * The paging arguments of a listing.
	 *
	 * @param string $what what is listed, for the descriptions
	 * @return array<string, mixed> limit and offset
	 */
	public static function page(string $what): array {
		return [
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => TablesPage::MAX_LIMIT, 'default' => TablesPage::DEFAULT_LIMIT,
				'description' => sprintf('Maximum number of %s returned (default %d, at most %d).', $what, TablesPage::DEFAULT_LIMIT, TablesPage::MAX_LIMIT)],
			'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0,
				'description' => sprintf('Number of %s to skip, from nextOffset of the previous page (default 0).', $what)],
		];
	}
}
