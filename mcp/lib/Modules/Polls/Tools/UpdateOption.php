<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Polls\Options\UpdateOptionHandler;
use OCA\Mcp\Modules\Polls\Options\UpdateOptionPreview;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;

/** polls_update_option: one independent grant, preview and native effect. */
final class UpdateOption implements WriteTool {
	/**
	 * @param UpdateOptionHandler $effect Confirmed mutation.
	 * @param UpdateOptionPreview $preview Effect-free plan.
	 * @return void
	 */
	public function __construct(private UpdateOptionHandler $effect, private UpdateOptionPreview $preview) {
	}

	/**
	 * @return ToolDefinition Published closed schema and exact grant.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If admission or native observation fails.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If the request is invalid.
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged If the confirmed snapshot changed.
	 */
	public static function definition(): ToolDefinition {
		return PollsWriteSchema::definition('polls_update_option');
	}

	/**
	 * @param array<string,mixed> $arguments Validated input.
	 * @param ToolContext $context Actor.
	 * @return array<string,mixed> MCP receipt envelope.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If admission or native observation fails.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If the request is invalid.
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged If the confirmed snapshot changed.
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->effect->handle($arguments, $context->userId));
	}

	/**
	 * @param array<string,mixed> $arguments Validated input.
	 * @param ToolContext $context Actor.
	 * @return array<string,mixed> Complete signed plan.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If admission or native observation fails.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If the request is invalid.
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged If the confirmed snapshot changed.
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($arguments, $context->userId);
	}
}
