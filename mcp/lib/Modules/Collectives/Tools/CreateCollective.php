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
use OCA\Mcp\Modules\Collectives\Creation\CreateCollectiveHandler;
use OCA\Mcp\Modules\Collectives\Creation\CreateCollectivePreview;
use OCA\Mcp\Modules\Collectives\Integration\FoundingCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Naming\CollectiveName;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Modules\Collectives\Presentation\FoundingMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_create_collective`: a new collective with its own new team, owned by the actor. */
final class CreateCollective implements WriteTool {
	/**
	 * @param CreateCollectiveHandler $handler the confirmed creation
	 * @param CreateCollectivePreview $preview the plan of the creation
	 */
	public function __construct(
		private CreateCollectiveHandler $handler,
		private CreateCollectivePreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_create_collective
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_create_collective', TeamsAdmission::CREATE_COLLECTIVE, FoundingMessages::TOOL_DESCRIPTION, [
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => FoundingMessages::PARAM_NAME],
			'emoji' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4 * CollectiveName::MAX_EMOJI, 'description' => FoundingMessages::PARAM_EMOJI],
			PlanState::ARGUMENT => PlanState::property(),
		], ['name'], [FoundingCapabilities::KEY]);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_create_collective arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_create_collective arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the call is not admitted, the name or emoji is refused, or the name is taken
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
