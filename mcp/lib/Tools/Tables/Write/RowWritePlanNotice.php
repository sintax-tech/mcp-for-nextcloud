<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\L10n\Translator;

/**
 * The warning a confirmed row write shows when the state is no longer the approved one.
 *
 * The cell lines name the values being written. A column limit such as numberMax is part of the plan state and was
 * absent from that text, so the warning names each numeric column's current numberMax.
 */
final class RowWritePlanNotice {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The warning, naming the numberMax of every numeric column the plan binds.
	 *
	 * @param RowWritePrepared $prepared the state found now
	 * @return string the message in the language of the current user
	 */
	public static function message(RowWritePrepared $prepared): string {
		$limits = [];
		foreach ($prepared->target->columns as $id => $column) {
			if (!is_array($column) || ($column['type'] ?? '') !== 'number' || !array_key_exists('numberMax', $column)) {
				continue;
			}
			$limits[(int)$id] = self::number($column['numberMax']);
		}
		ksort($limits);
		$shown = [];
		foreach ($limits as $id => $max) {
			$shown[] = $id . '=' . $max;
		}
		return Translator::t('The table, the view or the row changed since the plan: this is the new plan. numberMax: %s', [implode(', ', $shown)]);
	}

	/**
	 * One limit as text, without a trailing fraction on a whole number.
	 *
	 * @param mixed $value the column's numberMax
	 * @return string the limit, or an empty string when it is not a number
	 */
	private static function number(mixed $value): string {
		if (is_int($value)) {
			return (string)$value;
		}
		if (is_float($value)) {
			$text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
			return $text === '' ? '0' : $text;
		}
		return is_string($value) && is_numeric($value) ? $value : '';
	}
}
