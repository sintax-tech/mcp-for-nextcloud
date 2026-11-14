<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Dismissal;

use InvalidArgumentException;

/** One notification id, also checked when the tool is called without schema validation. */
final class DismissNotificationInput {
	/**
	 * @param array<string,mixed> $arguments id, and the optional confirm flag the registry injects
	 * @return int notification id of 1 or greater
	 * @throws InvalidArgumentException when the id is missing, not an integer, or below 1
	 */
	public function id(array $arguments): int {
		$id = $arguments['id'] ?? null;
		if (array_diff(array_keys($arguments), ['id', 'confirm']) !== [] || !is_int($id) || $id < 1) {
			throw new InvalidArgumentException();
		}
		return $id;
	}
}
