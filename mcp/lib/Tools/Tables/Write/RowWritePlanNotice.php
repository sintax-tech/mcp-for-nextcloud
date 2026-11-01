<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * The warning a confirmed row write shows when the state is no longer the approved one.
 *
 * The cell lines name the values being written. A column limit such as numberMax is part of the plan state and was
 * absent from that text, so the warning names each numeric column's current numberMax. The same limits are also one
 * line each: a single warning is cut at 300 characters, and the plan state is only a hash, so the previous limit
 * cannot be picked out of it. None of this is part of the plan state.
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
		return self::warning(self::shown($prepared));
	}

	/**
	 * The warning and the limits the row renderer shows. Both stay outside the plan state.
	 *
	 * @param RowWritePrepared $prepared the state found now
	 * @return array<string, mixed> warnings and one id=numberMax entry per numeric column
	 */
	public static function fields(RowWritePrepared $prepared): array {
		$shown = self::shown($prepared);
		return [
			'warnings' => [['message' => self::warning($shown)]],
			'numberLimits' => $shown,
		];
	}

	/**
	 * One inert line per numeric limit, complete.
	 *
	 * @param array<string, mixed> $plan the public plan
	 * @return list<string> the lines, or none when this plan has no limits
	 */
	public static function lines(array $plan): array {
		$limits = $plan['numberLimits'] ?? null;
		if (!is_array($limits)) {
			return [];
		}
		$lines = [];
		foreach ($limits as $limit) {
			if (is_string($limit) && $limit !== '') {
				$lines[] = '- ' . PlanText::quoted($limit);
			}
		}
		return $lines;
	}

	/**
	 * The warning sentence for limits already rendered as id=value.
	 *
	 * @param list<string> $shown the limits, in column order
	 * @return string the message in the language of the current user
	 */
	private static function warning(array $shown): string {
		return Translator::t('The table, the view or the row changed since the plan: this is the new plan. numberMax: %s', [implode(', ', $shown)]);
	}

	/**
	 * id=numberMax for every numeric column the plan binds, in column order.
	 *
	 * @param RowWritePrepared $prepared the state found now
	 * @return list<string> the limits
	 */
	private static function shown(RowWritePrepared $prepared): array {
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
		return $shown;
	}

	/**
	 * One limit as text. A float keeps the digits that distinguish it, including a very small or long one.
	 *
	 * @param mixed $value the column's numberMax
	 * @return string the limit, or an empty string when it is not a finite number
	 */
	private static function number(mixed $value): string {
		if (is_int($value)) {
			return (string)$value;
		}
		if (is_float($value)) {
			return is_finite($value) ? json_encode($value, JSON_THROW_ON_ERROR) : '';
		}
		return is_string($value) && is_numeric($value) ? $value : '';
	}
}
