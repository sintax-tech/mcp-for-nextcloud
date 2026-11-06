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
use OCA\Mcp\Modules\Polls\Options\RestoreOptionHandler;
use OCA\Mcp\Modules\Polls\Options\RestoreOptionPreview;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;

/** polls_restore_option: one independent grant, preview and native effect. */
final class RestoreOption implements WriteTool {
	/** @param RestoreOptionHandler $effect Confirmed mutation. @param RestoreOptionPreview $preview Effect-free plan. */
	public function __construct(private RestoreOptionHandler $effect, private RestoreOptionPreview $preview) {}
	/** @return ToolDefinition Published closed schema and exact grant. */
	public static function definition(): ToolDefinition { return PollsWriteSchema::definition('polls_restore_option'); }
	/** @param array<string,mixed> $arguments Validated input. @param ToolContext $context Actor. @return array<string,mixed> MCP receipt envelope. */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->effect->handle($arguments, $context->userId));
	}
	/** @param array<string,mixed> $arguments Validated input. @param ToolContext $context Actor. @return array<string,mixed> Complete signed plan. */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($arguments, $context->userId);
	}
}
