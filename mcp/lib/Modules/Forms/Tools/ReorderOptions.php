<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Modules\Forms\Reordering\ReorderOptionsHandler;
use OCA\Mcp\Modules\Forms\Reordering\ReorderOptionsPreview;
use OCA\Mcp\Modules\Forms\Presentation\FormsSchema;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Publishes forms_reorder_options. */
final class ReorderOptions implements WriteTool {
	/**
	 * @param ReorderOptionsHandler $handler the tool effect
	 * @param ReorderOptionsPreview $preview the inert plan
	 */
	public function __construct(private ReorderOptionsHandler $handler, private ReorderOptionsPreview $preview) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return FormsSchema::definition('forms_reorder_options');
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}

	/** {@inheritDoc} */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($arguments, $context->userId);
	}
}
