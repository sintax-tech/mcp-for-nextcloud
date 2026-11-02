<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Tables\Reading\ListColumnsHandler;
use OCA\Mcp\Modules\Tables\Presentation\TablesSchema;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;

/** Publishes and delegates ListColumns for Nextcloud Tables. */
final class ListColumns implements Tool {
	/**
	 * @param ListColumnsHandler $handler the read operation
	 */
	public function __construct(private ListColumnsHandler $handler) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return TablesSchema::definition(ListColumnsHandler::TOOL, TablesMessages::TOOL_LIST_COLUMNS, TablesSchema::columns());
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
