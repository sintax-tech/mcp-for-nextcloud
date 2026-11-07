<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Tables\Creation\{CreateColumnHandler, CreateColumnPreview};
use OCA\Mcp\Modules\Tables\Presentation\SchemaToolSchema;

/** Publishes and delegates the create_column capability. */
final class CreateColumn implements WriteTool {
	/**
	 * @param CreateColumnHandler $handler Confirmed operation.
	 * @param CreateColumnPreview $preview Read-only preparation.
	 */
	public function __construct(private CreateColumnHandler $handler, private CreateColumnPreview $preview) {}
	/** @return ToolDefinition The closed MCP definition. */
	public static function definition(): ToolDefinition { return SchemaToolSchema::definition('create_column'); }
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
