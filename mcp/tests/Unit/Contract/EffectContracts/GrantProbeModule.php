<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tools\ToolModule;

/**
 * Three Notes tools as their real module declares their grants (read, create, edit), so the real ToolRegistry can say
 * which of them a user is offered after an administrator's change: the grant decision of tools/list, without the
 * Notes app behind it. Calling one is never part of these contracts.
 */
final class GrantProbeModule implements ToolModule {
	/** @return list<array{name:string, description:string, inputSchema:array, module:string, operation:string}> the probed tools */
	public function definitions(): array {
		$schema = ['type' => 'object', 'properties' => new \stdClass()];
		return [
			['name' => 'notes_list', 'description' => 'List notes.', 'inputSchema' => $schema, 'module' => 'notes', 'operation' => 'read'],
			['name' => 'notes_create', 'description' => 'Create a note.', 'inputSchema' => $schema, 'module' => 'notes', 'operation' => 'create'],
			['name' => 'notes_edit', 'description' => 'Edit a note.', 'inputSchema' => $schema, 'module' => 'notes', 'operation' => 'edit'],
		];
	}

	/**
	 * @param string $name tool name
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user
	 * @return array{content: list<array{type:string, text:string}>} never returns
	 * @throws \LogicException always: the probe only lists
	 */
	public function call(string $name, array $arguments, string $userId): array {
		throw new \LogicException('The grant probe is listed, never called');
	}
}
