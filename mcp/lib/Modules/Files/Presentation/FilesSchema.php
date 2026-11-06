<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Common static Files schema construction. */
final class FilesSchema {
	/** Static definitions only. */
	private function __construct() {
	}
	/**
	 * @param array<string,mixed> $row tool metadata and JSON input schema
	 * @return ToolDefinition its unchanged catalog definition
	 */
	public static function definition(array $row): ToolDefinition {
		return new ToolDefinition($row['name'], $row['module'], $row['operation'], $row['description'], $row['inputSchema'], $row['app'] ?? null, $row['grantAnyOf'] ?? null, $row['destructiveHint'] ?? false);
	}
	/**
	 * @param array<string, array<string, mixed>> $properties
	 * @param list<string> $required
	 * @return array<string, mixed>
	 */
	public static function schema(array $properties, array $required = []): array {
		$schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
		return $required === [] ? $schema : $schema + ['required' => $required];
	}
}
