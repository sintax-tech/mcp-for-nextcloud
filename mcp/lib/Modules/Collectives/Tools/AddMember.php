<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Collectives\Addition\AddMemberHandler;
use OCA\Mcp\Modules\Collectives\Addition\AddMemberPreview;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_add_member`: an account or a group of this server joins a collective. */
final class AddMember implements WriteTool {
	/**
	 * @param AddMemberHandler $handler the confirmed addition
	 * @param AddMemberPreview $preview the plan of the addition
	 */
	public function __construct(
		private AddMemberHandler $handler,
		private AddMemberPreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_add_member
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_add_member', TeamsAdmission::MEMBERS, MembershipMessages::TOOL_ADD_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'type' => ['type' => 'string', 'enum' => ['user', 'group'], 'description' => MembershipMessages::PARAM_TYPE],
			'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => MembershipMessages::PARAM_ID],
			PlanState::ARGUMENT => PlanState::property(),
		], ['collective_id', 'type', 'id'], [TeamsCapabilities::KEY]);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_add_member arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_add_member arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the call is refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
