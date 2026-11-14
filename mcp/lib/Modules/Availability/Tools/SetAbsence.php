<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Availability\Editing\SetAbsenceHandler;
use OCA\Mcp\Modules\Availability\Editing\SetAbsencePreview;
use OCA\Mcp\Modules\Availability\Presentation\AvailabilitySchema;

/** availability_set_absence: create or change the actor's own absence through the native DAV service. */
final class SetAbsence implements WriteTool {
	/**
	 * @param SetAbsenceHandler $handler the confirmed write
	 * @param SetAbsencePreview $preview the plan of the write
	 */
	public function __construct(private SetAbsenceHandler $handler, private SetAbsencePreview $preview) {}
	/** @return ToolDefinition default-denied availability.absence write */
	public static function definition(): ToolDefinition { return AvailabilitySchema::setDefinition(); }
	/**
	 * @param array<string,mixed> $arguments validated arguments with confirm: true
	 * @param ToolContext $context actor
	 * @return array<string,mixed> receipt as JSON
	 */
	public function execute(array $arguments, ToolContext $context): array { return ToolResult::json($this->handler->handle($context->userId, $arguments)); }
	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param ToolContext $context actor
	 * @return array<string,mixed> the plan, never a side effect
	 */
	public function preview(array $arguments, ToolContext $context): array { return $this->preview->plan($context->userId, $arguments); }
}
