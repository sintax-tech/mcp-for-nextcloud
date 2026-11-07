<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/**
 * Whether the value RowService returned for a written cell is the effect the plan expected. A plain number goes through
 * NumberBusiness::parseValue(), json_encode(floatval($value)), and RowService::parseValueByColumnType() decodes it again,
 * so a whole number comes back as an integer (42) while the effect is a float (42.0): numbers of that column type are
 * compared by value, and only when both sides are numbers. Every other value, text included, is compared strictly, so a
 * rounded number, a cut or trimmed text, or a normalized option still counts as different.
 */
final class RowReceiptComparison {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Whether the returned value is the expected one.
	 *
	 * @param array<string, mixed> $column the column as Column::jsonSerialize() gives it
	 * @param mixed $effect the effect the plan expected ({@see CellValueDomain::cell()})
	 * @param mixed $returned the value RowService returned for that column
	 * @return bool true when Tables kept the value as expected
	 */
	public static function same(array $column, mixed $effect, mixed $returned): bool {
		if (($column['type'] ?? '') === 'number' && ($column['subtype'] ?? '') === ''
			&& (is_int($effect) || is_float($effect)) && (is_int($returned) || is_float($returned))) {
			return (float)$effect === (float)$returned;
		}
		return $effect === $returned;
	}
}
