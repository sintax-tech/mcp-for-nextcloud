<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Meetings;

/**
 * What each step of routines_create_meeting receives, from the arguments the user confirmed. The plan and the
 * confirmed call read the same values here, so the plan shows exactly what the steps get.
 */
final class MeetingRequest {
	/** The event field that receives the link of the new room. */
	public const LINK_FIELD = 'location';
	/** Arguments of routines_create_meeting that calendar_create_event takes under the same name. */
	private const EVENT_FIELDS = ['calendar', 'start', 'end', 'timeZone', 'description', 'send_invitations', 'confirm_shared'];

	/** Static mapping only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return string the room name, which is also the event title
	 */
	public static function title(array $arguments): string {
		return (string)$arguments['title'];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return list<string> local accounts invited to the room and to the event
	 */
	public static function participants(array $arguments): array {
		return array_values(array_map('strval', (array)$arguments['participants']));
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return string the agenda text
	 */
	public static function agenda(array $arguments): string {
		return (string)$arguments['agenda'];
	}

	/**
	 * The arguments of calendar_create_event, built field by field: `confirm` never reaches the event step, because the
	 * single confirmation of this tool is the WriteGate's, and the closed schema of the event would refuse it anyway.
	 *
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @param string|null $link the URL of the room, once it exists; null in the plan, where no room exists yet
	 * @return array<string, mixed> arguments of calendar_create_event
	 */
	public static function event(array $arguments, ?string $link): array {
		$event = ['summary' => self::title($arguments), 'attendees' => self::participants($arguments)]
			+ array_intersect_key($arguments, array_flip(self::EVENT_FIELDS));
		return $link === null ? $event : $event + [self::LINK_FIELD => $link];
	}
}
