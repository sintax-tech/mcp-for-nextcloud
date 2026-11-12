<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use DateTimeImmutable;
use DateTimeZone;

/** Wall-clock grid mapped through constant-offset segments: skip DST gaps and keep both offsets of a fold. */
final class AvailabilityCandidates {
	/**
	 * @param array{from:DateTimeImmutable,to:DateTimeImmutable,zone:DateTimeZone,duration:int,step:int,weekdays:list<int>,workStart:int,workEnd:int,index:int} $search normalized seven-day search
	 * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}> chronological exact instants, deduplicated
	 */
	public function build(array $search): array {
		$lower = $search['from']->getTimestamp();
		$upper = $search['to']->getTimestamp();
		$transitions = $search['zone']->getTransitions($lower - 86400, $upper + 86400);
		$pieces = [];
		$starts = [];
		// These are nominal UTC dates, deliberately not zone->modify('+1 day'), which can normalize a skipped date.
		$day = new DateTimeImmutable($search['from']->format('Y-m-d'), new DateTimeZone('UTC'));
		$last = $search['to']->format('Y-m-d');
		for (; $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
			if (!in_array((int)$day->format('N'), $search['weekdays'], true)) continue;
			$wallStart = $day->getTimestamp() + $search['workStart'] * 60;
			$wallEnd = $day->getTimestamp() + $search['workEnd'] * 60;
			foreach ($transitions as $i => $transition) {
				$offset = $transition['offset'];
				$segmentStart = $transition['ts'];
				$segmentEnd = $transitions[$i + 1]['ts'] ?? $upper + 86400;
				$a = max($segmentStart, $wallStart - $offset, $lower);
				$b = min($segmentEnd, $wallEnd - $offset, $upper);
				if ($a >= $b) continue;
				$pieces[] = [$a, $b];
				// Grid is anchored to the user's daily start, not to UTC or the server/account timezone.
				for ($wall = $wallStart; $wall < $wallEnd; $wall += $search['step'] * 60) {
					$instant = $wall - $offset;
					if ($instant >= $a && $instant < $b) $starts[$instant] = true;
				}
			}
		}
		usort($pieces, static fn ($a, $b) => $a[0] <=> $b[0]);
		$merged = [];
		foreach ($pieces as [$a, $b]) {
			$lastIndex = count($merged) - 1;
			if ($lastIndex >= 0 && $a <= $merged[$lastIndex][1]) $merged[$lastIndex][1] = max($b, $merged[$lastIndex][1]);
			else $merged[] = [$a, $b];
		}
		ksort($starts, SORT_NUMERIC);
		$out = [];
		foreach (array_keys($starts) as $start) {
			$end = $start + $search['duration'] * 60;
			foreach ($merged as [$a, $b]) {
				if ($start >= $a && $end <= $b) {
					$out[] = ['start' => (new DateTimeImmutable('@' . $start))->setTimezone($search['zone']),
						'end' => (new DateTimeImmutable('@' . $end))->setTimezone($search['zone'])];
					break;
				}
			}
		}
		return $out;
	}
}
