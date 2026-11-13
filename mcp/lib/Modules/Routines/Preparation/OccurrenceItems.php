<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

/** The occurrences inside a calendar_list_events answer. Recurrence and CLASS stay in that reader. */
final class OccurrenceItems {
	/** Decoding only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $result the answer of MeetingEventRead
	 * @return list<array<string, mixed>> the occurrence items the reader returned
	 */
	public static function fromResult(array $result): array {
		if (($result['isError'] ?? false) === true) {
			return [];
		}
		$items = [];
		foreach ((array)($result['content'] ?? []) as $block) {
			if (!is_array($block)) {
				continue;
			}
			$decoded = json_decode((string)($block['text'] ?? ''), true);
			if (!is_array($decoded)) {
				continue;
			}
			$rows = array_is_list($decoded) ? $decoded : [$decoded];
			foreach ($rows as $row) {
				if (is_array($row) && isset($row['uid'], $row['start'])) {
					$items[] = $row;
				}
			}
		}
		return $items;
	}

	/**
	 * The companion block calendar_list_events adds when the window was cut.
	 *
	 * @param array<string, mixed> $result the answer of MeetingEventRead
	 * @return array{truncated: bool, limit: int}|null that block, when the reader sent one
	 */
	public static function cut(array $result): ?array {
		if (($result['isError'] ?? false) === true) {
			return null;
		}
		foreach ((array)($result['content'] ?? []) as $block) {
			if (!is_array($block)) {
				continue;
			}
			$decoded = json_decode((string)($block['text'] ?? ''), true);
			if (!is_array($decoded) || array_is_list($decoded) || !array_key_exists('truncated', $decoded) || isset($decoded['uid'], $decoded['start'])) {
				continue;
			}
			return ['truncated' => $decoded['truncated'] === true, 'limit' => (int)($decoded['limit'] ?? 0)];
		}
		return null;
	}
}
