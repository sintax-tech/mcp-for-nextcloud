<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Explicit schedule, bounded work and plain parameter continuation for a read-only search. */
final class SuggestionSchema {
	/** Fixed English guidance for the model, not a claim that an offered slot remains available. */
	public const NOTE = 'Suggests at most 5 meeting windows for up to 20 exact local account IDs. Checks at most 32 candidates per call over at most 7 local days, using a duration of 15–240 real minutes and a wall-clock step of 15 or 30 minutes anchored to the supplied work start. Supply working weekdays (1=Monday to 7=Sunday) and a same-day working interval, with 24:00 allowed only as the end; no work schedule is assumed. ISO from/to offsets define the horizon; timezone is explicit IANA. DST gaps are skipped and both offsets of repeated times are considered in chronological order. A window is suggested only if all listed participants are known to be free; busy, missing or unknown is never free. The authenticated user\'s own calendar is included only when their account ID is listed in participants. actor_included reports whether the authenticated user\'s own calendar is among the checked participants. Coverage describes this page only; partial/truncated and next_index report unexamined or unknown coverage. To continue deliberately, pass the returned continuation object as the next call arguments; it contains the same parameters and next index. The index is plain, not signed or an approval, and changing parameters starts a different search. Grants and scope are checked afresh on every call. Never automatically loop over the horizon, retry unknown windows or claim an empty partial page proves no free time. Nothing is reserved, written or sent.';

	/** Static schemas only. */
	private function __construct() {}

	/** @return ToolDefinition strict opt-in read, same grant and app as calendar_check_availability */
	public static function definition(): ToolDefinition {
		return CalendarSchema::definition('calendar_suggest_times', 'read', self::NOTE, [
			'participants' => AvailabilitySchema::participants(),
			'from' => CalendarSchema::date('Inclusive horizon start, ISO timestamp with seconds and Z/offset.'),
			'to' => CalendarSchema::date('Exclusive horizon end, at most seven local days after from.'),
			'timezone' => CalendarSchema::text('Explicit IANA timezone for work hours and candidate presentation.', 1, 64),
			'duration_minutes' => ['type' => 'integer', 'minimum' => 15, 'maximum' => 240],
			'step_minutes' => ['type' => 'integer', 'enum' => [15, 30]],
			'working_hours' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['weekdays', 'start', 'end'], 'properties' => [
				'weekdays' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 7, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]],
				'start' => CalendarSchema::text('Local same-day work start, HH:MM.', 5, 5),
				'end' => CalendarSchema::text('Local work end, HH:MM; 24:00 is permitted.', 5, 5),
			]],
			'next_index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1024, 'description' => 'Plain index into this exact parameter set; omit for the first page.'],
		], ['participants', 'from', 'to', 'timezone', 'duration_minutes', 'step_minutes', 'working_hours'], grantAnyOf: ['availability']);
	}
}
