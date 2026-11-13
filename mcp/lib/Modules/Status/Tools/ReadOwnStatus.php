<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Status\Presentation\StatusSchema;
use OCA\Mcp\Modules\Status\Reading\ReadOwnStatusHandler;

/** Publishes status_read_own through its single read handler. */
final class ReadOwnStatus implements Tool {
	/** @param ReadOwnStatusHandler $status the single read dependency */
	public function __construct(private ReadOwnStatusHandler $status) {
	}

	/** @return ToolDefinition opt-in status read */
	public static function definition(): ToolDefinition {
		return StatusSchema::definition();
	}

	/**
	 * @param array<string, mixed> $arguments validated input; ignored, the actor comes from the context
	 * @param ToolContext $context actor
	 * @return array<string, mixed> MCP result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->status->handle($context->userId);
	}
}
