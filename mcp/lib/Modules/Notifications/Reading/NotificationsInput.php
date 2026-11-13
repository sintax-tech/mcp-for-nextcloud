<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Notifications\Reading;

use InvalidArgumentException;

/** Strict bound parsing, also enforced when the tool is invoked without schema validation. */
final class NotificationsInput {
	/**
	 * @param array<string,mixed> $arguments only an optional integer limit
	 * @return int bounded native read size
	 * @throws InvalidArgumentException if a caller supplies a target user, cursor or invalid bound
	 */
	public function limit(array $arguments): int {
		$limit = $arguments['limit'] ?? 50;
		if (array_diff(array_keys($arguments), ['limit']) !== [] || !is_int($limit) || $limit < 1 || $limit > 200
			|| (array_key_exists('limit', $arguments) && $arguments['limit'] === null)) throw new InvalidArgumentException();
		return $limit;
	}
}
