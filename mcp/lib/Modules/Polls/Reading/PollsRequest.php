<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Reading;

use InvalidArgumentException;

/** Closed, strictly typed arguments; direct module calls receive the same validation as registry calls. */
final class PollsRequest {
	/**
	 * Validates one of the two PO-1 requests.
	 * @param array<string,mixed> $arguments supplied arguments
	 * @param bool $read whether the request reads one poll's options
	 * @return array{limit:int,cursor:?string,id:?int} normalized request
	 * @throws InvalidArgumentException for malformed or extra input
	 */
	public static function of(array $arguments, bool $read): array {
		$limitKey = $read ? 'options_limit' : 'limit';
		$cursorKey = $read ? 'options_cursor' : 'cursor';
		$allowed = $read ? ['poll_id', $limitKey, $cursorKey] : [$limitKey, $cursorKey];
		$limit = $arguments[$limitKey] ?? ($read ? 100 : 25);
		$cursor = $arguments[$cursorKey] ?? null;
		$id = $arguments['poll_id'] ?? null;
		if (array_diff(array_keys($arguments), $allowed) !== [] || !is_int($limit) || $limit < 1 || $limit > 100
			|| (array_key_exists($limitKey, $arguments) && $arguments[$limitKey] === null)
			|| (array_key_exists($cursorKey, $arguments) && (!is_string($cursor) || $cursor === '' || strlen($cursor) > 512))
			|| ($read && (!is_int($id) || $id < 1))) {
			throw new InvalidArgumentException('Invalid Polls arguments');
		}
		return ['limit' => $limit, 'cursor' => $cursor, 'id' => $id];
	}
}
