<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Status\Editing\SetOwnStatusMessageHandler;
use OCA\Mcp\Modules\Status\Editing\SetOwnStatusMessagePreview;
use OCA\Mcp\Modules\Status\Presentation\StatusMessageSchema;

/** Publishes status_set_message. Arguments cannot name another user or an icon. */
final class SetOwnStatusMessage implements WriteTool {
	/**
	 * @param SetOwnStatusMessageHandler $effect confirmed write
	 * @param SetOwnStatusMessagePreview $plans effect-free plan
	 */
	public function __construct(private SetOwnStatusMessageHandler $effect, private SetOwnStatusMessagePreview $plans) {
	}

	/** @return ToolDefinition confirmed edit of the actor's own message */
	public static function definition(): ToolDefinition {
		return StatusMessageSchema::definition();
	}

	/**
	 * @param array<string, mixed> $arguments validated input
	 * @param ToolContext $context actor
	 * @return array<string, mixed> MCP result of the read-back
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->effect->apply($context->userId, $arguments));
	}

	/**
	 * @param array<string, mixed> $arguments validated input
	 * @param ToolContext $context actor
	 * @return array<string, mixed> plan, with nothing written
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($context->userId, $arguments);
	}
}
