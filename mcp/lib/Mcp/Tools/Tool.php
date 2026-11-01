<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/**
 * One MCP tool of a {@see CatalogModule}: a thin class that validates and normalizes its arguments, hands them to
 * one class that does the work and wraps the answer. The grant, the optional app, Guests, the capabilities it
 * requires and the confirmation of writes are decided by {@see ToolRegistry} before it runs, never by the tool.
 */
interface Tool {
	/**
	 * What the tool publishes, read without building the tool or any service it needs.
	 *
	 * @return ToolDefinition the definition of this tool
	 */
	public static function definition(): ToolDefinition;

	/**
	 * Runs the tool. For a {@see WriteTool} this is the confirmed call, after the plan was shown.
	 *
	 * @param array<string, mixed> $arguments arguments already validated against the published input schema
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> MCP result envelope
	 * @throws \InvalidArgumentException when the arguments are invalid (-32602)
	 */
	public function execute(array $arguments, ToolContext $context): array;
}
