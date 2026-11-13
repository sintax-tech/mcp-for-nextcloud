<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use stdClass;

/** Shared schema and fixed guide note for the read-only absence slice. */
final class AvailabilitySchema {
	/** English model guidance, shared by discovery and guide. */
	public const NOTE = 'Reads the authenticated user\'s own out-of-office absence as stored by Nextcloud; there is no user argument and no other account can be read. Returns configured=false when none is stored. Otherwise returns state (in_effect, upcoming or ended at read time), inclusive first_day/last_day (YYYY-MM-DD), timezone, starts_at/ends_at (ISO 8601, last day until 23:59), the short status, the message and the replacement user, if any. An ended absence is reported as stored; nothing is cleared. Refused when the administrator turned absence settings off. The read never creates, changes or clears an absence, schedules or removes background jobs, sends events or refreshes the absence cache.';
	/** Static schema only. */
	private function __construct() {}
	/** @return ToolDefinition argument-free read with the default-denied availability.read grant */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('availability_read_absence', 'availability', 'read', self::NOTE,
			['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false], app: 'dav', requires: ['availability.native']);
	}
}
