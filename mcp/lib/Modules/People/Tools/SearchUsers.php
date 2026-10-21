<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\Search\PeopleSearch;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

final class SearchUsers implements Tool {
    public function __construct(private PeopleSearch $search) {}

    public static function definition(): ToolDefinition {
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
                    'query' => ['type' => 'string', 'minLength' => PeopleSearch::MIN_TERM, 'maxLength' => PeopleSearch::MAX_TERM,
                        'description' => 'Name, account ID or e-mail to look for (2 to 100 characters).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => PeopleSearch::MAX_LIMIT, 'default' => PeopleSearch::DEFAULT_LIMIT,
                        'description' => 'Maximum number of accounts returned (default 10, at most 25).'],
                    'include_groups' => ['type' => 'boolean', 'default' => false,
                        'description' => 'Also match groups, for sharing with a group: they come back with the type "group" and the group ID (default false).'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        // The native sharing search uses the authenticated host session, not a supplied UID.
        return ToolResult::json($this->search->search($arguments));
    }
}
