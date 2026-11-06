<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Tables\Editing\{UpdateTableHandler, UpdateTablePreview};
use OCA\Mcp\Modules\Tables\Presentation\SchemaToolSchema;

/** Publishes and delegates the update_table capability. */
final class UpdateTable implements WriteTool {
	/**
	 * @param UpdateTableHandler $handler Confirmed operation.
	 * @param UpdateTablePreview $preview Read-only preparation.
	 */
	public function __construct(private UpdateTableHandler $handler, private UpdateTablePreview $preview) {}
	/** @return ToolDefinition The closed MCP definition. */
	public static function definition(): ToolDefinition { return SchemaToolSchema::definition('update_table'); }
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
