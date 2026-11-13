<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Availability\Presentation\AvailabilitySchema;
use OCA\Mcp\Modules\Availability\Reading\ReadAbsenceHandler;

/** Own current or planned absence, read without writing, scheduling or dispatching anything. */
final class ReadAbsence implements Tool {
	/** @param ReadAbsenceHandler $absence the single read dependency */
	public function __construct(private ReadAbsenceHandler $absence) {}
	/** @return ToolDefinition opt-in availability read */
	public static function definition(): ToolDefinition { return AvailabilitySchema::definition(); }
	/**
	 * @param array<string,mixed> $arguments no arguments; the account is always the actor
	 * @param ToolContext $context actor
	 * @return array<string,mixed> MCP result
	 */
	public function execute(array $arguments, ToolContext $context): array { return $this->absence->handle($context->userId); }
}
