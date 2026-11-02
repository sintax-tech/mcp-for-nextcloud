<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\SearchPeopleHandler;

/** The published schema of the People search, without a dependency on another Nextcloud app. */
final class PeopleSchema {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @return ToolDefinition the definition of users_search, preserving its historical published name
	 */
	public static function usersSearch(): ToolDefinition {
		return new ToolDefinition(
			name: 'users_search',
			module: 'people',
			operation: 'read',
			description: 'Searches the accounts of this Nextcloud by name, account ID or e-mail and returns their account IDs '
				. '(uid), display names and, when available, e-mail; every row carries a type, "user" for an account. '
				. 'Use it whenever you are not sure of someone\'s account ID. Groups are not searched by default: to also match '
				. 'groups, pass include_groups: true, and the group rows come back with the type "group" and the group ID, '
				. 'which is what a share takes as group:<id>.',
			inputSchema: [
				'type' => 'object',
				'properties' => [
					'query' => ['type' => 'string', 'minLength' => SearchPeopleHandler::MIN_TERM, 'maxLength' => SearchPeopleHandler::MAX_TERM,
						'description' => 'Name, account ID or e-mail to look for (2 to 100 characters).'],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => SearchPeopleHandler::MAX_LIMIT, 'default' => SearchPeopleHandler::DEFAULT_LIMIT,
						'description' => 'Maximum number of accounts returned (default 10, at most 25).'],
					'include_groups' => ['type' => 'boolean', 'default' => false,
						'description' => 'Also match groups, for sharing with a group: they come back with the type "group" and the group ID (default false).'],
				],
				'required' => ['query'],
				'additionalProperties' => false,
			],
		);
	}
}
