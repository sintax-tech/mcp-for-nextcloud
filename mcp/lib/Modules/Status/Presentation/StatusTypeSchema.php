<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Schema of status_set_type. The actor is the caller; there is no user id argument. */
final class StatusTypeSchema {
	/** English model guidance, shared by discovery and the guide. */
	public const NOTE = 'Changes the authenticated user\'s own status type and nothing else. status is one of online, away, dnd, busy, invisible or offline. Without confirm=true the call returns a plan and writes nothing. With confirm=true it calls the same pair the status menu uses: set the type as user-defined with no explicit timestamp, then remove the temporary backup status. The stored message is left as it is. The result is a fresh read of the configured snapshot, not live presence, and it includes no emoji or icon. Another person\'s id is not accepted.';

	/** Static schema only. */
	private function __construct() {
	}

	/** @return ToolDefinition confirmed edit of the actor's own status type */
	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			'status_set_type',
			'status',
			'edit',
			self::NOTE,
			[
				'type' => 'object',
				'additionalProperties' => false,
				'required' => ['status'],
				'properties' => [
					'status' => ['type' => 'string', 'enum' => StatusTypes::VALUES],
				],
			],
			app: 'user_status',
			requires: ['status.native'],
		);
	}
}
