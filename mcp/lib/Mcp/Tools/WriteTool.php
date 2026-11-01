<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/**
 * A tool whose operation is not `read`: it describes the write before doing it. The plan is shown to the person,
 * and only the same call repeated with `confirm: true` runs {@see Tool::execute()}; nothing is stored in between.
 */
interface WriteTool extends Tool {
	/**
	 * Describes what the confirmed call would do. May read anything it needs and must never write.
	 *
	 * @param array<string, mixed> $arguments arguments already validated against the published input schema
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan, never a side effect
	 * @throws \InvalidArgumentException for a semantic problem the JSON Schema cannot express (-32602)
	 */
	public function preview(array $arguments, ToolContext $context): array;
}
