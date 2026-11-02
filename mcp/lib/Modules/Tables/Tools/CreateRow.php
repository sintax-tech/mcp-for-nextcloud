<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Tables\Creation\CreateRowHandler;
use OCA\Mcp\Modules\Tables\Presentation\TablesSchema;
use OCA\Mcp\Modules\Tables\Creation\CreateRowPreview;

/** Publishes and delegates CreateRow for Nextcloud Tables. */
final class CreateRow implements WriteTool {
	/**
	 * @param CreateRowHandler $handler the confirmed effect
	 * @param CreateRowPreview $preview the effect-free plan
	 */
	public function __construct(private CreateRowHandler $handler, private CreateRowPreview $preview) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return TablesSchema::write(false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}

	/** {@inheritDoc} */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->preview($arguments, $context->userId);
	}
}
