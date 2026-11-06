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
use OCA\Mcp\Modules\Polls\Sharing\AddShareHandler;
use OCA\Mcp\Modules\Polls\Sharing\AddSharePreview;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;

/** polls_add_share: one independent grant, preview and native effect. */
final class AddShare implements WriteTool {
	/** @param AddShareHandler $effect Confirmed mutation. @param AddSharePreview $preview Effect-free plan. */
	public function __construct(private AddShareHandler $effect, private AddSharePreview $preview) {}
	/** @return ToolDefinition Published closed schema and exact grant. */
	public static function definition(): ToolDefinition { return PollsWriteSchema::definition('polls_add_share'); }
	/** @param array<string,mixed> $arguments Validated input. @param ToolContext $context Actor. @return array<string,mixed> MCP receipt envelope. */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->effect->handle($arguments, $context->userId));
	}
	/** @param array<string,mixed> $arguments Validated input. @param ToolContext $context Actor. @return array<string,mixed> Complete signed plan. */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($arguments, $context->userId);
	}
}
