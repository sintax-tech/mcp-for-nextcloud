<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Closed published contracts for the eight schema/sharing tools. */
final class SchemaToolSchema {
	/**
	 * @param string $action Closed action name, without the tables_ prefix.
	 * @return ToolDefinition The discovery definition; no optional app code is loaded.
	 */
	public static function definition(string $action): ToolDefinition {
		$id = ['type' => 'integer', 'minimum' => 1];
		$text = ['type' => 'string', 'maxLength' => 10000];
		$title = ['type' => 'string', 'minLength' => 1, 'maxLength' => 200];
		$emoji = ['type' => 'string', 'maxLength' => 32];
		$bool = ['type' => 'boolean'];
		$items = ['type' => 'array', 'maxItems' => 100];
		$sharing = in_array($action, ['share_node', 'unshare_node'], true);
		$properties = [];
		$required = [];
		$patch = [];
		if ($sharing) {
			$properties = ['node_type' => ['type' => 'string', 'enum' => ['table', 'view']], 'node_id' => $id];
			$required = ['node_type', 'node_id'];
			if ($action === 'share_node') {
				$properties['recipient'] = self::object(['type' => ['type' => 'string', 'enum' => ['user', 'group']], 'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['type', 'id']);
				$properties['permissions'] = self::object(array_fill_keys(['read', 'create', 'update', 'delete', 'manage'], $bool), ['read', 'create', 'update', 'delete', 'manage']);
				$required = [...$required, 'recipient', 'permissions'];
			} else { $properties['share_id'] = $id;
			$required[] = 'share_id'; }
		} else {
			if ($action !== 'create_table') { $properties['table_id'] = $id;
			$required[] = 'table_id'; }
			if ($action === 'update_column') { $properties['column_id'] = $id;
			$required[] = 'column_id'; }
			if ($action === 'update_view') { $properties['view_id'] = $id;
			$required[] = 'view_id'; }
			$patch = ['title' => $title, 'description' => $text, 'emoji' => $emoji];
			if (str_ends_with($action, 'column')) {
				$patch = ['title' => $title, 'description' => $text, 'mandatory' => $bool, 'options' => ['type' => 'array', 'maxItems' => 20, 'description' => 'Unique {name,value} entries. Each value is checked against the column type; no raw JSON.']];
			}
			if ($action === 'update_table') $patch['archived'] = $bool;
			if ($action === 'update_view') $patch += [
				'columns' => $items + ['items' => $id, 'description' => 'Complete ordered list of unique visible column IDs. Empty clears it. Existing relation references can only be preserved.'],
				'filter' => $items + ['description' => 'Complete list of nonempty AND groups, combined with OR. Each rule is {column_id,operator,value}; value is a supported scalar or Tables placeholder. Empty list clears all filters. No relation edits.'],
				'sort' => $items + ['items' => self::object(['column_id' => $id, 'mode' => ['type' => 'string', 'enum' => ['ASC', 'DESC']]], ['column_id', 'mode']), 'description' => 'Complete ordered unique sort rules; empty clears sorting. No relation edits.'],
			];
			if (str_starts_with($action, 'update_')) {
				$properties['patch'] = self::object($patch);
				$required[] = 'patch';
			} else {
				if ($action === 'create_view') unset($patch['description']);
				$properties += $patch;
				$required[] = 'title';
				if ($action === 'create_column') {
					$properties += ['type' => ['type' => 'string', 'enum' => ['text', 'number', 'selection', 'datetime', 'usergroup']], 'subtype' => ['type' => 'string', 'enum' => ['', 'line', 'long', 'rich', 'link', 'stars', 'progress', 'multi', 'check', 'date', 'time'], 'description' => 'Validated together with type; the empty subtype is explicit.'], 'view_ids' => $items + ['items' => $id, 'description' => 'Unique managed view IDs in this table to include the new column in.']];
					$required = [...$required, 'type', 'subtype'];
				}
			}
		}
		$properties += ['confirm_shared' => $bool, 'plan_state' => PlanState::property()];
		$description = match ($action) {
			'create_table' => 'Create one empty Tables table using only the custom template. Plan first; no columns or views are created.',
			'update_table' => 'Update table title, description, emoji or archived state with native manage permission. Plan first; rows are preserved.',
			'create_column' => 'Create a typed column in a managed local table. No relation type. A duplicate title may receive a native suffix. Plan first.',
			'update_column' => 'Update column metadata and typed options without changing type or subtype. Preserved fields are written again from a snapshot. No relation edits. Plan first.',
			'create_view' => 'Create one empty view in a managed local table. Plan first; rules are edited in a separate call.',
			'update_view' => 'Update a managed view and its complete columns, filter groups or sort. Rules can hide rows without deleting them. Relation references are preserved only. Plan first.',
			'share_node' => 'Share a managed local table or view with an existing user or group and five explicit native permissions. No public links. An identical share is a no-op; different permissions require separate revocation. Plan first.',
			'unshare_node' => 'Revoke one user/group share of a managed local table or view. Other shares may continue granting access. Plan first.',
		};
		return TablesSchema::definition('tables_' . $action, $description, $properties, $sharing ? 'share' : 'schema', $required,
			str_starts_with($action, 'update_') || $action === 'unshare_node', [$sharing ? 'tables.native_sharing_api' : 'tables.native_schema_api']);
	}

	/**
	 * @param array<string,mixed> $properties Fields.
	 * @param list<string> $required Required fields.
	 * @return array<string,mixed> Closed object.
	 */
	private static function object(array $properties, array $required = []): array {
		return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
	}
}
