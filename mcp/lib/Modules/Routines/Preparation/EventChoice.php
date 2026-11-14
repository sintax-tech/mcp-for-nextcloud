<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

/**
 * Picks one occurrence the calendar reader already returned.
 *
 * A private or confidential event is absent here only when that reader omitted it. This class does not read CLASS.
 */
final class EventChoice {
	/** Selection only. */
	private function __construct() {
	}

	/**
	 * @param list<array<string, mixed>> $items occurrences from the reader
	 * @param array<string, mixed> $arguments validated arguments
	 * @return array<string, mixed>|null the chosen occurrence, or null when none is visible
	 */
	public static function pick(array $items, array $arguments): ?array {
		$uid = isset($arguments['uid']) ? (string)$arguments['uid'] : null;
		$calendar = isset($arguments['calendar']) ? (string)$arguments['calendar'] : null;
		$from = (string)$arguments['from'];
		$chosen = null;
		foreach ($items as $item) {
			if ($uid !== null && (string)$item['uid'] !== $uid) {
				continue;
			}
			if ($calendar !== null && (string)($item['calendar'] ?? '') !== $calendar) {
				continue;
			}
			if ($uid === null && !self::overlaps($item, $from, (string)$arguments['to'])) {
				continue;
			}
			if ($chosen === null || (string)$item['start'] < (string)$chosen['start']) {
				$chosen = $item;
			}
		}
		return $chosen;
	}

	/**
	 * @param array<string, mixed> $item one occurrence
	 * @param string $from start of the requested window
	 * @param string $to end of the requested window
	 * @return bool whether the occurrence meets the window
	 */
	private static function overlaps(array $item, string $from, string $to): bool {
		$start = (string)$item['start'];
		$end = (string)($item['end'] ?? $start);
		return $start < $to && $end > $from;
	}
}
