<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** The bounded single-window availability input, separate from event creation. */
final class AvailabilitySchema {
	/** Guide and tool description are fixed English text, as for the existing tool catalog. */
	public const NOTE = 'Checks one 15–240 minute window for up to 20 exact local account IDs, with an explicit IANA timezone and ISO timestamps with offsets. Returns only free, busy or unknown, never appointment details. The authenticated user\'s own calendar is included only when their account ID is listed in participants. External, ambiguous, missing or uncheckable participants are unknown, never free. Collection-restricted Calendar access is refused because the native free/busy API cannot preserve that restriction. The explicit window has no search horizon; past or distant dates use the native result. No reservation, invitation or other write is made.';

	/** Static definitions only. */
	private function __construct() {}

	/** @return ToolDefinition read operation with the separate, default-denied availability grant */
	public static function definition(): ToolDefinition {
		return CalendarSchema::definition('calendar_check_availability', 'read', self::NOTE, [
			'participants' => self::participants(),
			'start' => CalendarSchema::date('Inclusive ISO timestamp with Z or an explicit offset.'),
			'end' => CalendarSchema::date('Exclusive ISO timestamp with Z or an explicit offset, 15–240 minutes after start.'),
			'timezone' => CalendarSchema::text('Explicit IANA timezone used to display the window; offsets define the instants, including daylight-saving overlaps.', 1, 64),
		], ['participants', 'start', 'end', 'timezone'], grantAnyOf: ['availability']);
	}

	/** @return array<string, mixed> exact bounded account IDs shared by both availability reads */
	public static function participants(): array {
		return [
			'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'uniqueItems' => true,
			'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
			'description' => 'Exact local account IDs, not display names. External addresses or unknown IDs remain unknown; no account search is performed.',
		];
	}
}
