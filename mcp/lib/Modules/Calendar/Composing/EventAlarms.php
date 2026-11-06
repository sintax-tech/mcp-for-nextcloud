<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Composing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Locating\StoredEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Component\VAlarm;
use Sabre\VObject\DateTimeParser;
use Throwable;

/** Inspects and replaces only unambiguous DISPLAY reminders relative to DTSTART. */
final class EventAlarms {
	/**
	 * @param VEvent $event master to change
	 * @param array<string, mixed> $arguments optional alarms input
	 * @return void absent input leaves every node untouched
	 */
	public static function apply(VEvent $event, array $arguments): void {
		if (!array_key_exists('alarms', $arguments)) {
			return;
		}
		$input = AlarmInput::read($arguments['alarms']);
		foreach ($event->select('VALARM') as $alarm) {
			if (self::replaceable($alarm)) {
				$event->remove($alarm);
			}
		}
		foreach ($input as $item) {
			$text = $item['description'] ?? '';
			if ($text === '') {
				$text = (string)($event->SUMMARY ?? '');
			}
			$alarm = $event->add('VALARM', [
				'ACTION' => 'DISPLAY',
				'DESCRIPTION' => $text === '' ? Translator::t('Event reminder') : $text,
			]);
			$alarm->add('TRIGGER', $item['minutesBefore'] === 0 ? 'PT0M' : '-PT' . $item['minutesBefore'] . 'M', ['RELATED' => 'START']);
		}
	}

	/**
	 * @param VAlarm $alarm existing component
	 * @return bool true only for a valid DISPLAY/relative START component
	 */
	public static function replaceable(VAlarm $alarm): bool {
		return count($alarm->select('ACTION')) === 1
			&& strtoupper((string)$alarm->ACTION) === 'DISPLAY'
			&& self::offset($alarm) !== null
			&& self::parameter($alarm, 'RELATED', 'START') === 'START';
	}

	/**
	 * @param VAlarm $alarm component with a relative trigger
	 * @return int|null signed seconds, or null for absolute/invalid/ambiguous data
	 */
	public static function offset(VAlarm $alarm): ?int {
		if (count($alarm->select('TRIGGER')) !== 1 || $alarm->TRIGGER->getValueType() !== 'DURATION' || self::parameter($alarm, 'VALUE', 'DURATION') !== 'DURATION') {
			return null;
		}
		$value = (string)$alarm->TRIGGER;
		// Sabre's duration parser is permissive; do not discard malformed components it might repair.
		if (!preg_match('/^[+-]?P(?:[0-9]+W|(?:[0-9]+D(?:T(?:[0-9]+H(?:[0-9]+M)?(?:[0-9]+S)?|[0-9]+M(?:[0-9]+S)?|[0-9]+S))?|T(?:[0-9]+H(?:[0-9]+M)?(?:[0-9]+S)?|[0-9]+M(?:[0-9]+S)?|[0-9]+S)))$/D', $value)) {
			return null;
		}
		try {
			$d = DateTimeParser::parseDuration($value);
			$seconds = $d->d * 86400 + $d->h * 3600 + $d->i * 60 + $d->s;
			return is_int($seconds) ? ($d->invert ? -$seconds : $seconds) : null;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @param VAlarm $alarm inspected component
	 * @param string $name trigger parameter
	 * @param string $default missing parameter value
	 * @return string unambiguous enum, or empty string
	 */
	public static function parameter(VAlarm $alarm, string $name, string $default): string {
		$matches = [];
		foreach ($alarm->TRIGGER?->parameters() ?? [] as $parameter) {
			if ($parameter->name === $name) {
				$matches = array_merge($matches, $parameter->getParts());
			}
		}
		return $matches === [] ? $default : (count($matches) === 1 ? strtoupper($matches[0]) : '');
	}

	/**
	 * @param VCalendar|null $calendar event object
	 * @return array{managed:list<array<string, mixed>>, preserved:list<array<string, mixed>>} safe projection
	 */
	public static function inspect(?VCalendar $calendar): array {
		$out = ['managed' => [], 'preserved' => []];
		$master = $calendar === null ? null : (new StoredEvent(0, '', '', $calendar))->master();
		foreach ($master?->select('VALARM') ?? [] as $index => $alarm) {
			$offset = self::offset($alarm);
			$row = [
				'index' => $index, 'action' => (string)($alarm->ACTION ?? ''),
				'related' => self::parameter($alarm, 'RELATED', 'START'),
				'trigger' => (string)($alarm->TRIGGER ?? ''),
				'offsetSeconds' => $offset,
				'description' => (string)($alarm->DESCRIPTION ?? ''),
				'repeat' => (string)($alarm->REPEAT ?? ''),
			];
			$out[self::replaceable($alarm) ? 'managed' : 'preserved'][] = $row;
		}
		return $out;
	}

	/**
	 * @param VCalendar|null $calendar full object
	 * @return list<string> recurrence identities and their canonical reminders only
	 */
	public static function overrides(?VCalendar $calendar): array {
		$out = [];
		foreach ($calendar?->select('VEVENT') ?? [] as $event) {
			if (isset($event->{'RECURRENCE-ID'})) {
				$out[] = json_encode([$event->{'RECURRENCE-ID'}->jsonSerialize(), self::eventFingerprints($event, null)], JSON_THROW_ON_ERROR);
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * @param VCalendar|null $calendar stored object
	 * @param bool $managed which partition to compare
	 * @return list<string> canonical reminder properties, with multiplicity
	 */
	public static function fingerprints(?VCalendar $calendar, bool $managed): array {
		$master = $calendar === null ? null : (new StoredEvent(0, '', '', $calendar))->master();
		return self::eventFingerprints($master, $managed);
	}

	/**
	 * @param VEvent|null $event master or individually changed occurrence
	 * @param bool|null $managed partition, or null for all reminders
	 * @return list<string> sorted property fingerprints preserving multiplicity
	 */
	private static function eventFingerprints(?VEvent $event, ?bool $managed): array {
		$out = [];
		foreach ($event?->select('VALARM') ?? [] as $alarm) {
			if ($managed === null || self::replaceable($alarm) === $managed) {
				$value = clone $alarm;
				if (self::replaceable($alarm)) {
					unset($value->TRIGGER);
					$value->add('TRIGGER', (string)self::offset($alarm), ['RELATED' => 'START']);
				}
				$properties = $value->jsonSerialize();
				sort($properties[1]);
				$out[] = json_encode($properties, JSON_THROW_ON_ERROR);
			}
		}
		sort($out);
		return $out;
	}
}
