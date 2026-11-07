<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Polls\Creation\CreatePollHandler;
use OCA\Mcp\Modules\Polls\Creation\CreatePollPreview;
use OCA\Mcp\Modules\Polls\Presentation\PollsSchema;

/** `polls_create_poll`: one private simple text or date poll owned by the caller, with its initial options. */
final class CreatePoll implements WriteTool {
	/**
	 * @param CreatePollHandler $creates the confirmed creation
	 * @param CreatePollPreview $plans the plan of the creation
	 */
	public function __construct(
		private CreatePollHandler $creates,
		private CreatePollPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of polls_create_poll
	 */
	public static function definition(): ToolDefinition {
		return PollsSchema::create();
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm and plan_state
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the honest receipt as JSON: created, partial or uncertain
	 * @throws ArgumentValidationException for malformed arguments
	 * @throws PlanChanged when the confirmed plan no longer matches
	 * @throws ToolFailure when the creation is refused before any write
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->creates->handle($arguments, $context->userId);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan of the poll the call would create, without writing
	 * @throws ArgumentValidationException for malformed arguments
	 * @throws ToolFailure when the creation is refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
