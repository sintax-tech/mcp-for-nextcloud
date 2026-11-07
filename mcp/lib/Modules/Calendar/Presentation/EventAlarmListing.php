<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use Sabre\VObject\Component\VEvent;
use OCA\Mcp\Platform\Dav\Calendar;

/** Projects occurrence reminders without exposing confidential event content. */
final class EventAlarmListing {
	/** Fixed English description and guide note, matching the tool catalog convention. */
	public const NOTE = 'calendar_list_events includes alarms (minutesBefore and description) for DISPLAY reminders relative to the start, and otherAlarms counts other reminders without exposing their details. Confidential events and read-only shares do not expose reminders.';

	/** @return string reminder guidance in the authenticated user's language */
	public static function note(): string {
		return Translator::t('calendar_list_events includes alarms (minutesBefore and description) for DISPLAY reminders relative to the start, and otherAlarms counts other reminders without exposing their details. Confidential events and read-only shares do not expose reminders.');
	}

	/** Static projection only. */
	private function __construct() {
	}

	/**
	 * @param VEvent $event the actual occurrence, including an override's own reminders
	 * @param string $visibility classification already resolved by the read handler
	 * @param Calendar $calendar effective ownership and write permission
	 * @param string $userId authenticated viewer
	 * @return array<string, mixed> additive fields, omitted for busy-only events and read-only shares
	 */
	public static function fields(VEvent $event, string $visibility, Calendar $calendar, string $userId): array {
		if ($visibility !== Classification::FULL || ($calendar->ownedByOther($userId) && !$calendar->writable)) {
			return [];
		}
		$alarms = [];
		$other = 0;
		foreach ($event->select('VALARM') as $alarm) {
			if (!EventAlarms::replaceable($alarm)) {
				$other++;
				continue;
			}
			$seconds = -EventAlarms::offset($alarm);
			$alarms[] = [
				'minutesBefore' => $seconds % 60 === 0 ? intdiv($seconds, 60) : $seconds / 60,
				'description' => (string)($alarm->DESCRIPTION ?? ''),
			];
		}
		return ['alarms' => $alarms, 'otherAlarms' => $other];
	}
}
