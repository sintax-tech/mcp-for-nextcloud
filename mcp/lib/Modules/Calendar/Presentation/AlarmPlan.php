<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;

/** Opt-in reminder plan data and its bounded human-readable projection. */
final class AlarmPlan {
	/**
	 * @param string $name published write name
	 * @param PreparedCalendarWrite $prepared in-memory draft
	 * @param array<string, mixed> $arguments validated input
	 * @return array<string, mixed> no extra fields when alarms was omitted
	 */
	public static function extend(string $name, PreparedCalendarWrite $prepared, array $arguments): array {
		if (!in_array($name, ['calendar_create_event', 'calendar_update_event'], true) || !array_key_exists('alarms', $arguments)) {
			return [];
		}
		$before = EventAlarms::inspect($prepared->before?->vcalendar);
		$after = EventAlarms::inspect($prepared->after);
		return ['alarmChange' => [
			'scope' => 'master', 'operation' => 'replace-display-start',
			'before' => $before['managed'], 'after' => $after['managed'], 'preserved' => $before['preserved'],
			'overrideCount' => count(EventAlarms::overrides($prepared->before?->vcalendar)),
		]];
	}

	/**
	 * @param array<string, mixed> $plan complete calendar plan, including account timezone and shared notices
	 * @return list<string> translated lines, or none without alarms
	 */
	public static function lines(array $plan): array {
		$change = $plan['alarmChange'] ?? null;
		if (!is_array($change)) {
			return [];
		}
		$zone = $plan['timezone'] ?? 'UTC';
		$lines = [
			Translator::t('- DISPLAY reminders relative to the start: %s → %s', [
				self::listing($change['before'], $zone), self::listing($change['after'], $zone),
			]),
			Translator::t('- Reminders kept: %s', [self::listing($change['preserved'], $zone)]),
		];
		if (($plan['after']['allDay'] ?? false) === true) {
			$lines[] = Translator::t('- All-day reminders are relative to the start of the day in the calendar client.');
		}
		return $lines;
	}

	/**
	 * @param array<string, mixed> $plan fully assembled plan, including scheduling warnings and sharing
	 * @return array<string, mixed> reminder consequences survive custom-renderer fallback
	 */
	public static function withWarnings(array $plan): array {
		if (!isset($plan['alarmChange'])) {
			return $plan;
		}
		$plan['warnings'][] = ['type' => 'reminder_delivery', 'message' => Translator::t('Reminders are stored on the event; notification delivery is not verified.')];
		$count = $plan['alarmChange']['overrideCount'];
		if ($count > 0) {
			$plan['warnings'][] = ['type' => 'reminder_overrides', 'message' => AlarmMessages::overrides($count)];
		}
		if (($plan['shared'] ?? []) !== []) {
			$plan['warnings'][] = ['type' => 'reminder_shared', 'message' => Translator::t('Reminders are stored in the shared event and appear or notify for people using this calendar.')];
		}
		return $plan;
	}

	/**
	 * @param list<array<string, mixed>> $rows safe reminder summaries
	 * @param string $zone account timezone
	 * @return string first five reminders plus explicit counts for the remainder
	 */
	private static function listing(array $rows, string $zone): string {
		if ($rows === []) {
			return Translator::t('(none)');
		}
		$items = [];
		foreach (array_slice($rows, 0, 5) as $row) {
			$items[] = self::reminder($row, $zone);
		}
		$extra = array_slice($rows, 5);
		if ($extra !== []) {
			$counts = array_count_values(array_map(static fn (array $r): string => PlanText::inline($r['action'], 40), $extra));
			$types = [];
			foreach ($counts as $action => $count) {
				$types[] = $count . ' ' . ($action === '' ? '?' : $action);
			}
			$items[] = Translator::t('+%s more reminders: %s', [count($extra), implode(', ', $types)]);
		}
		return implode('; ', $items);
	}

	/**
	 * @param array<string, mixed> $row inspected reminder, never raw ICS
	 * @param string $zone account timezone
	 * @return string escaped action, anchor, trigger, description and repetition
	 */
	private static function reminder(array $row, string $zone): string {
		$seconds = $row['offsetSeconds'];
		$anchor = match ($row['related']) {
			'START' => Translator::t('start'),
			'END' => Translator::t('end'),
			default => Translator::t('unrecognized anchor'),
		};
		if ($seconds !== null) {
			$when = $seconds === 0 ? Translator::t('at %s', [$anchor])
				: Translator::t('%s seconds %s %s', [abs($seconds), $seconds < 0 ? Translator::t('before') : Translator::t('after'), $anchor]);
			if ($seconds < 0 && $seconds % 60 === 0) {
				$when = Translator::t('%s minutes before %s', [abs($seconds) / 60, $anchor]);
			}
		} else {
			$instant = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $row['trigger'], new DateTimeZone('UTC'));
			$when = $instant === false ? Translator::t('absolute or unrecognized trigger')
				: $instant->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i T');
		}
		$text = PlanText::inline($row['description'], 300);
		if (mb_strlen($row['description']) > 300) {
			$text .= ' ' . Translator::t('(text shortened)');
		}
		$repeat = $row['repeat'] === '' ? '' : Translator::t(' (repeat: %s)', [PlanText::inline($row['repeat'], 40)]);
		return PlanText::inline($row['action'], 40) . ': ' . $when . ' — ' . $text . $repeat;
	}
}
