<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;

/** Reminder messages and tool-description additions. */
final class AlarmMessages {
	/** Description delta repeated verbatim by tools/list and mcp_guide. */
	public const CREATE = ' Supports optional DISPLAY reminders relative to the start via alarms (up to 5, at most 4 weeks).';
	/** Description delta explains the selective replacement contract. */
	public const UPDATE = ' Optional alarms replaces only DISPLAY reminders relative to the start (up to 5, at most 4 weeks); [] removes those reminders. Omit alarms to preserve all reminders. Other reminders and recurrence overrides stay unchanged.';

	/**
	 * @param string $status applied, partial or uncertain
	 * @param string $uid known event UID
	 * @param bool $creating whether this request creates the event
	 * @return string honest observation, with no delivery claim
	 */
	public static function receipt(string $status, string $uid, bool $creating): string {
		if (!$creating && $status !== 'applied') {
			return $status === 'partial'
				? Translator::t('Event %s exists, but its reminders differ from the requested change. Do not repeat the change; check List events.', [$uid])
				: Translator::t('The change to event %s could not be verified. Do not repeat the change; check List events.', [$uid]);
		}
		return match ($status) {
			'applied' => Translator::t('Reminders were observed on the server. Notification delivery was not verified.'),
			'partial' => Translator::t('Event %s exists, but its reminders differ from the requested change. Do not repeat the creation; check List events.', [$uid]),
			default => Translator::t('The write of event %s could not be verified. Do not repeat the creation; check List events.', [$uid]),
		};
	}

	/**
	 * @param int $count individually changed occurrences
	 * @return string consequence of changing only master reminders
	 */
	public static function overrides(int $count): string {
		return Translator::n('%n individually changed occurrence keeps its own reminders and does not receive these reminders.', '%n individually changed occurrences keep their own reminders and do not receive these reminders.', $count);
	}
}
