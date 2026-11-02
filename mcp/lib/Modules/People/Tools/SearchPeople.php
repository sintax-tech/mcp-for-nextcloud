<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People\Tools;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\Presentation\PeopleSchema;
use OCA\Mcp\Modules\People\SearchPeopleHandler;

/** `users_search`: Nextcloud accounts, and optionally groups, found through the sharing search. */
final class SearchPeople implements Tool {
	/**
	 * @param SearchPeopleHandler $searches the account and group search
	 */
	public function __construct(
		private SearchPeopleHandler $searches,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of users_search
	 */
	public static function definition(): ToolDefinition {
		return PeopleSchema::usersSearch();
	}

	/**
	 * The sharing search reads the current session, so the context is deliberately not passed to the handler.
	 *
	 * @param array{query:string, limit?:int, include_groups?:bool} $arguments validated arguments, defaults applied
	 * @param ToolContext $context who calls
	 * @return array{content: list<array{type:string, text:string}>} the matching rows as JSON
	 * @throws InvalidArgumentException for a term out of bounds or a limit out of range
	 * @throws ToolFailure when the core search fails, with a fixed message that never carries the term
	 * @throws \JsonException when the answer cannot be encoded
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->searches->handle($arguments));
	}
}
