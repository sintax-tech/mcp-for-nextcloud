<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Only the approved 1.4.0 opt-in additions may extend the released upgrade snapshot. */
final class RoutineCatalogAdditions {
	/** @var array<string,list<string>> Exact 1.4.0 additions, all denied; Mail saving remains outside G-1. */
	public const PAIRS = [
		'files' => ['tags', 'comments'], 'calendar' => ['availability'],
		'activity' => ['read'], 'notifications' => ['read', 'dismiss'],
		'availability' => ['read', 'absence'], 'status' => ['read', 'edit'],
		'routines' => ['prepare', 'meeting', 'mailtask'],
	];

	/**
	 * @param array<string,list<string>> $catalog Exact approved catalog through 1.3.0.
	 * @return array<string,list<string>> Catalog with the ordered 1.4.0 additions.
	 */
	public static function catalog(array $catalog): array {
		foreach (self::PAIRS as $module => $operations) {
			$catalog[$module] = [...($catalog[$module] ?? []), ...$operations];
		}
		return $catalog;
	}

	/**
	 * @param array<string,list<string>> $baseline Catalog read from the actual released baseline.
	 * @return bool Whether it improperly includes a candidate-only operation.
	 */
	public static function overlaps(array $baseline): bool {
		foreach (self::PAIRS as $module => $operations) {
			if (array_intersect($baseline[$module] ?? [], $operations) !== []) return true;
		}
		return false;
	}

	/**
	 * @param array<string,array<string,bool>> $grants Actual complete candidate answer for one snapshot user.
	 * @return array<string,array<string,bool>> Previous-version observations, without changing any legacy value.
	 * @throws \UnexpectedValueException If any new pair is absent, granted, or a new module has extra operations.
	 */
	public static function previous(array $grants): array {
		foreach (self::PAIRS as $module => $operations) {
			foreach ($operations as $operation) {
				if (($grants[$module][$operation] ?? null) !== false) {
					throw new \UnexpectedValueException("Unexpected $module.$operation default in the 1.4.0 legacy matrix");
				}
				unset($grants[$module][$operation]);
			}
			if (!in_array($module, ['files', 'calendar'], true)) {
				if ($grants[$module] !== []) throw new \UnexpectedValueException("Unexpected $module operations in the 1.4.0 legacy matrix");
				unset($grants[$module]);
			}
		}
		return $grants;
	}
}
