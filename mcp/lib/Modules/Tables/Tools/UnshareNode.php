<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Tables\Sharing\{UnshareNodeHandler, UnshareNodePreview};
use OCA\Mcp\Modules\Tables\Presentation\SchemaToolSchema;

/** Publishes and delegates the unshare_node capability. */
final class UnshareNode implements WriteTool {
	/**
	 * @param UnshareNodeHandler $handler Confirmed operation.
	 * @param UnshareNodePreview $preview Read-only preparation.
	 */
	public function __construct(private UnshareNodeHandler $handler, private UnshareNodePreview $preview) {}
	/** @return ToolDefinition The closed MCP definition. */
	public static function definition(): ToolDefinition { return SchemaToolSchema::definition('unshare_node'); }
	/**
	 * @param array<string,mixed> $arguments Arguments.
	 * @param ToolContext $context Caller.
	 * @return array<string,mixed> Result.
	 */
	public function execute(array $arguments, ToolContext $context): array { return $this->handler->handle($arguments, $context->userId); }
	/**
	 * @param array<string,mixed> $arguments Arguments.
	 * @param ToolContext $context Caller.
	 * @return array<string,mixed> Plan.
	 */
	public function preview(array $arguments, ToolContext $context): array { return $this->preview->preview($arguments, $context->userId); }
}
