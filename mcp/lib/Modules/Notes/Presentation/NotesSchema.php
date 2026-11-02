<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/**
 * The input schemas the Notes tools publish: the definition every tool of the module shares (module `notes`, app
 * `notes`, no additional property) and the properties several tools repeat.
 */
final class NotesSchema {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $name tool name
	 * @param string $operation grant operation
	 * @param string $description English description for the model
	 * @param array<string, array<string, mixed>> $properties input properties, in published order
	 * @param list<string> $required required properties
	 * @return ToolDefinition the definition of one Notes tool
	 */
	public static function definition(string $name, string $operation, string $description, array $properties, array $required = []): ToolDefinition {
		$schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
		return new ToolDefinition(
			name: $name,
			module: 'notes',
			operation: $operation,
			description: $description,
			inputSchema: $required === [] ? $schema : $schema + ['required' => $required],
			app: 'notes',
		);
	}

	/**
	 * @return array<string, mixed> the note id property
	 */
	public static function id(): array {
		return ['type' => 'integer', 'minimum' => 1, 'description' => NotesMessages::PARAM_ID];
	}

	/**
	 * @return array<string, mixed> the optimistic-concurrency ETag property
	 */
	public static function etag(): array {
		return ['type' => 'string', 'description' => NotesMessages::PARAM_ETAG];
	}

	/**
	 * Notes live inside the user's own folder by construction, so this only ever matters when an administrator
	 * pointed the notes path at a share, a team folder or an external storage.
	 *
	 * @return array<string, mixed> the shared-write confirmation property
	 */
	public static function confirmShared(): array {
		return ['type' => 'boolean', 'description' => NotesMessages::PARAM_CONFIRM_SHARED];
	}

	/**
	 * @param string $description what the category means for this tool
	 * @return array<string, mixed> an optional category property, empty by default
	 */
	public static function category(string $description): array {
		return ['type' => 'string', 'default' => '', 'description' => $description];
	}
}
