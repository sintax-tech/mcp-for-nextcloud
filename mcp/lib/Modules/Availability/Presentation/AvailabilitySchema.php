<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Availability\Editing\AbsenceInput;
use stdClass;

/** Schemas and fixed guide notes of the absence tools. */
final class AvailabilitySchema {
	/** English model guidance, shared by discovery and guide. */
	public const NOTE = 'Reads the authenticated user\'s own out-of-office absence as stored by Nextcloud; there is no user argument and no other account can be read. Returns configured=false when none is stored. Otherwise returns state (in_effect, upcoming or ended at read time), inclusive first_day/last_day (YYYY-MM-DD), timezone, starts_at/ends_at (ISO 8601, last day until 23:59), the short status, the message and the replacement user, if any. An ended absence is reported as stored; nothing is cleared. Refused when the administrator turned absence settings off. The read never creates, changes or clears an absence, schedules or removes background jobs, sends events or refreshes the absence cache.';
	/** English model guidance of the create-or-update tool. */
	public const SET_NOTE = 'Creates or changes the authenticated user\'s own out-of-office absence through Nextcloud, the same way the absence settings do; no other account can be changed. first_day and last_day are inclusive real days (YYYY-MM-DD), interpreted in the user\'s time zone from 00:00 of the first day to 23:59 of the last. status is the short text (at most 100 characters) shown as user status during the absence; message (at most 4000 characters) is shown to others. Omit replacement_user_id to keep the current replacement, send null to remove it, or send an existing user ID to set it. Without confirm: true the answer is the plan (before and after, time zone, native effects) and nothing changes; repeat with confirm: true and the plan_state only after the user agrees. Nextcloud then stores the absence, adds or updates an out-of-office calendar event and schedules start/end background jobs for this absence only. Refused when the administrator turned absence settings off. Removing an absence is not offered.';
	/** Static schema only. */
	private function __construct() {}
	/** @return ToolDefinition argument-free read with the default-denied availability.read grant */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('availability_read_absence', 'availability', 'read', self::NOTE,
			['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false], app: 'dav', requires: ['availability.native']);
	}
	/** @return ToolDefinition confirmed write with the default-denied availability.absence grant; an update overwrites, like other edits */
	public static function setDefinition(): ToolDefinition {
		$day = ['type' => 'string', 'minLength' => 10, 'maxLength' => 10, 'format' => 'date'];
		return new ToolDefinition('availability_set_absence', 'availability', 'absence', self::SET_NOTE, ['type' => 'object', 'additionalProperties' => false, 'properties' => [
			'first_day' => $day + ['description' => 'First day of the absence, inclusive, YYYY-MM-DD.'],
			'last_day' => $day + ['description' => 'Last day of the absence, inclusive, YYYY-MM-DD; not before first_day.'],
			'status' => ['type' => 'string', 'maxLength' => AbsenceInput::STATUS_MAX, 'description' => 'Short status shown during the absence.'],
			'message' => ['type' => 'string', 'maxLength' => AbsenceInput::MESSAGE_MAX, 'description' => 'Longer message shown to others during the absence.'],
			'replacement_user_id' => ['type' => ['string', 'null'], 'maxLength' => AbsenceInput::NAME_MAX, 'description' => 'Existing user ID of the replacement; null removes it; omitted keeps the current one.'],
			PlanState::ARGUMENT => PlanState::property(),
		], 'required' => ['first_day', 'last_day', 'status', 'message']], app: 'dav', destructiveHint: true, requires: ['availability.native', 'availability.write']);
	}
}
