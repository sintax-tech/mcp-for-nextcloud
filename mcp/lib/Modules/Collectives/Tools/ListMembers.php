<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Modules\Collectives\Reading\ListMembersHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_list_members`: the members of one collective; a read admitted by the `members` grant (decision D2). */
final class ListMembers implements Tool {
	/** @param ListMembersHandler $handler the listing */
	public function __construct(private ListMembersHandler $handler) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_list_members
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_list_members', 'read', MembershipMessages::TOOL_LIST_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			...CollectivesSchema::paging(),
		], ['collective_id'], [TeamsCapabilities::KEY], [TeamsAdmission::MEMBERS]);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_list_members arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the answer as JSON
	 * @throws ToolFailure when the call is refused or Teams fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}
}
