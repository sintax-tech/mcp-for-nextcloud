<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\Tools\Calendar\AttendeeResolver;
use OCA\Mcp\Tools\Calendar\CalendarMessages;

/** Calendar-specific schema fragments; shared DAV properties live in the protocol layer. */
final class CalendarSchema {
	/** Grant module of Calendar tools. */
	public const MODULE = 'calendar';
	/** Required Nextcloud app. */
	public const APP = 'calendar';

	/**
	 * @param string $description English source text shown to calendar-tool clients
	 * @return array<string, mixed> date or timestamp property
	 */
	public static function date(string $description): array {
		return ['type' => 'string', 'minLength' => 10, 'maxLength' => 40, 'description' => $description];
	}

	/**
	 * @param bool $allowEmpty update accepts [] to remove every attendee
	 * @return array<string, mixed> guest list property: internal account ids only
	 */
	public static function attendees(bool $allowEmpty = false): array {
		return [
			'type' => 'array',
			'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
			'minItems' => $allowEmpty ? 0 : 1,
			'maxItems' => AttendeeResolver::MAX_ATTENDEES,
			'uniqueItems' => true,
			'description' => CalendarMessages::PROP_ATTENDEES,
		];
	}

	/**
	 * @return array<string, mixed> opt-in invitation property, defaulting to sending nothing
	 */
	public static function sendInvitations(): array {
		return [
			'type' => 'boolean',
			'default' => false,
			'description' => CalendarMessages::PROP_SEND_INVITATIONS,
		];
	}
}
