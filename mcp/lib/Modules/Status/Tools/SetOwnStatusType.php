<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Status\Editing\SetOwnStatusTypeHandler;
use OCA\Mcp\Modules\Status\Presentation\StatusTypeSchema;

/** Publishes status_set_type. Arguments cannot name another user. */
final class SetOwnStatusType implements WriteTool {
	/** @param SetOwnStatusTypeHandler $status plan and confirmed write */
	public function __construct(private SetOwnStatusTypeHandler $status) {
	}

	/** @return ToolDefinition confirmed edit of the actor's own type */
	public static function definition(): ToolDefinition {
		return StatusTypeSchema::definition();
	}

	/**
	 * @param array<string, mixed> $arguments validated input; only status is read
	 * @param ToolContext $context actor
	 * @return array<string, mixed> MCP result of the read-back
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->status->apply($context->userId, (string)$arguments['status']));
	}

	/**
	 * @param array<string, mixed> $arguments validated input; only status is read
	 * @param ToolContext $context actor
	 * @return array<string, mixed> plan, with nothing written
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->status->plan($context->userId, (string)$arguments['status']);
	}
}
