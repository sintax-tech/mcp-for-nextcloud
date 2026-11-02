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
use OCA\Mcp\Modules\Tables\Reading\ListRowsHandler;
use OCA\Mcp\Modules\Tables\Presentation\TablesSchema;
use OCA\Mcp\Modules\Tables\Presentation\RowsMessages;
use OCA\Mcp\Modules\Tables\Reading\RowsRequest;

/** Publishes and delegates ListRows for Nextcloud Tables. */
final class ListRows implements Tool {
	/**
	 * @param ListRowsHandler $handler the read operation
	 */
	public function __construct(private ListRowsHandler $handler) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return TablesSchema::definition(ListRowsHandler::TOOL, RowsMessages::TOOL, RowsRequest::schema());
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
