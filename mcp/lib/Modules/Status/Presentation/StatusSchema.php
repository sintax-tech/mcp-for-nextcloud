<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Shared schema and fixed guide note for the configured status snapshot. */
final class StatusSchema {
	/** English model guidance, deliberately shared by discovery and guide. */
	public const NOTE = 'Reads the authenticated user\'s own configured user status and nothing else. The answer is the stored snapshot: status, status_timestamp, user_defined, message_id, an accessible message, clear_at, status_message_timestamp, and expired. expired is true only when clear_at is set and already earlier than the current time; the stored row is never changed. A missing row answers configured=false and "No status is configured." and does not create a row. This is not live presence: automatic online expiry, calendar status and heartbeat are not applied. No emoji, icon or custom icon is returned. A custom message, when set, is the accessible text; otherwise a known predefined message id is shown as fixed accessible text. An unknown message id is returned as message_id with message null. snapshot is always "configured" and live_presence is always false.';

	/** Static schema only. */
	private function __construct() {
	}

	/** @return ToolDefinition opt-in status read with no arguments */
	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			'status_read_own',
			'status',
			'read',
			self::NOTE,
			['type' => 'object', 'additionalProperties' => false, 'properties' => new \stdClass()],
			app: 'user_status',
			requires: ['status.native'],
		);
	}
}
