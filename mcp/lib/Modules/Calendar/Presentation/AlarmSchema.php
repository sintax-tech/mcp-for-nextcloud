<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

/** Closed reminder input shared by the two event writes. */
final class AlarmSchema {
	/** @return array<string, mixed> optional list; deliberately no default */
	public static function property(): array {
		return [
			'type' => 'array', 'minItems' => 0, 'maxItems' => 5,
			'description' => 'Optional DISPLAY reminders relative to the start (start of day for all-day events), up to 4 weeks. Omit to keep current behavior. On update, replaces only DISPLAY reminders relative to the start; [] removes those reminders. Other reminders and recurrence overrides stay unchanged.',
			'items' => [
				'type' => 'object', 'additionalProperties' => false, 'required' => ['minutesBefore'],
				'properties' => [
					'minutesBefore' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 40320, 'description' => 'Minutes before the event starts; 0 means at the start.'],
					'description' => ['type' => 'string', 'minLength' => 0, 'maxLength' => 65536, 'description' => 'Reminder text. Omitted or empty uses the final event title, or Event reminder when untitled.'],
				],
			],
		];
	}
}
