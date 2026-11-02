<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/**
 * The one reading of the limits of a number column, for a written value and for a hidden value a view adds alike: the
 * range of the subtype (stars 0 to 5, progress 0 to 100, none for a plain number) and the column's own numberMin and
 * numberMax, which RowService::validateColumnValueLimits() applies to every numeric value whatever the subtype (1.0.10,
 * 2.2.2 and later; 1.0.0 and 2.0.0 apply none, and the hidden values of a view are never checked by Tables).
 */
final class NumberBounds {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Whether a number fits the subtype's range and the column's limits.
	 *
	 * @param array<string, mixed> $column the column as Column::jsonSerialize() gives it
	 * @param int|float $value the number
	 * @return bool true when it fits
	 */
	public static function fits(array $column, int|float $value): bool {
		[$min, $max] = match ((string)($column['subtype'] ?? '')) {
			'stars' => [0, 5],
			'progress' => [0, 100],
			default => [null, null],
		};
		return is_finite((float)$value)
			&& ($min === null || $value >= $min) && ($max === null || $value <= $max)
			&& (!is_numeric($column['numberMin'] ?? null) || $value >= (float)$column['numberMin'])
			&& (!is_numeric($column['numberMax'] ?? null) || $value <= (float)$column['numberMax']);
	}
}
