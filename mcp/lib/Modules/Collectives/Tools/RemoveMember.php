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
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Modules\Collectives\Removal\RemoveMemberHandler;
use OCA\Mcp\Modules\Collectives\Removal\RemoveMemberPreview;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_remove_member`: one member leaves a collective, or a pending invitation or request is cancelled. */
final class RemoveMember implements WriteTool {
	/**
	 * @param RemoveMemberHandler $handler the confirmed removal
	 * @param RemoveMemberPreview $preview the plan of the removal
	 */
	public function __construct(
		private RemoveMemberHandler $handler,
		private RemoveMemberPreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_remove_member
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_remove_member', TeamsAdmission::MEMBERS, MembershipMessages::TOOL_REMOVE_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'member_id' => CollectivesSchema::memberId(),
			PlanState::ARGUMENT => PlanState::property(),
		], ['collective_id', 'member_id'], [TeamsCapabilities::KEY], destructiveHint: true);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_remove_member arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_remove_member arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the call is refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
