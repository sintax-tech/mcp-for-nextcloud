<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Schema of status_set_message. The actor is the caller; there is no user id or icon argument. */
final class StatusMessageSchema {
	/** English model guidance, shared by discovery and the guide. */
	public const NOTE = 'Changes the authenticated user\'s own status message and nothing else. Pass exactly one of message (custom text, at most 80 characters), message_id (meeting, commuting, sick-leave, vacationing, out-of-office, be-right-back, remote-work, call, availability or busy-tentative), or clear=true. An empty message does not remove it. clear_at is an optional unix time in the future and is rejected together with clear. No emoji, icon, or customIcon argument is accepted. Without confirm=true the call returns a plan and writes nothing. With confirm=true a custom message calls setCustomMessage with a null icon, a predefined id calls setPredefinedMessage, and clear calls clearMessage. setCustomMessage and setPredefinedMessage then remove the temporary backup status, as the status menu does; clearMessage does not. Either write clears a stored custom icon and leaves the status type as it is. The result is a fresh read of the configured snapshot, not live presence, and it includes no emoji or icon. Another person\'s id is not accepted.';

	/** Static schema only. */
	private function __construct() {
	}

	/** @return ToolDefinition confirmed edit of the actor's own status message */
	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			'status_set_message',
			'status',
			'edit',
			self::NOTE,
			[
				'type' => 'object',
				'additionalProperties' => false,
				'properties' => [
					'message' => ['type' => 'string'],
					'message_id' => ['type' => 'string', 'enum' => StatusMessageRequest::IDS],
					'clear' => ['type' => 'boolean'],
					'clear_at' => ['type' => 'integer'],
				],
			],
			app: 'user_status',
			requires: ['status.native'],
		);
	}
}
