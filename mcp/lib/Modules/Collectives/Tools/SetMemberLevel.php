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
use OCA\Mcp\Modules\Collectives\Editing\SetMemberLevelHandler;
use OCA\Mcp\Modules\Collectives\Editing\SetMemberLevelPlan;
use OCA\Mcp\Modules\Collectives\Editing\SetMemberLevelPreview;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_set_member_level`: another level for one member of a collective; it may take away rights. */
final class SetMemberLevel implements WriteTool {
	/**
	 * @param SetMemberLevelHandler $handler the confirmed change
	 * @param SetMemberLevelPreview $preview the plan of the change
	 */
	public function __construct(
		private SetMemberLevelHandler $handler,
		private SetMemberLevelPreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_set_member_level
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_set_member_level', TeamsAdmission::MEMBERS, MembershipMessages::TOOL_LEVEL_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'member_id' => CollectivesSchema::memberId(),
			'level' => ['type' => 'string', 'enum' => array_keys(SetMemberLevelPlan::LEVELS), 'description' => MembershipMessages::PARAM_LEVEL],
			PlanState::ARGUMENT => PlanState::property(),
		], ['collective_id', 'member_id', 'level'], [TeamsCapabilities::KEY], destructiveHint: true);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_set_member_level arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_set_member_level arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the call is refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
