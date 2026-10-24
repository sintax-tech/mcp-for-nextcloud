<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Keeps the complete legacy operation matrix, explicitly allowing only the reviewed Mail read addition. */
final class LegacyCatalogContract {
	/**
	 * @param array<string, list<string>> $baseline catalog actually read from the released 1.0.8 code
	 * @param array<string, list<string>> $current candidate catalog
	 * @return array<string, list<string>> unchanged baseline operation matrix for before/after comparisons
	 * @throws \UnexpectedValueException on removal, changed operations or an unrecognized addition
	 */
	public static function baseline(array $baseline, array $current): array {
		if (isset($baseline['mail']) || $current !== [...$baseline, 'mail' => ['read']]) {
			throw new \UnexpectedValueException('Legacy catalog changed beyond the explicit mail.read addition');
		}
		return $baseline;
	}

	/**
	 * @param array<string, array<string, mixed>> $answers branch observations over the complete legacy matrix
	 * @return array<string, array<string, mixed>> all observations unchanged except the explicitly checked new matrix cell
	 * @throws \UnexpectedValueException if Mail's new default is anything other than read-only Allow
	 */
	public static function answers(array $answers): array {
		foreach ($answers as &$state) {
			foreach ($state['matrix'] as &$row) {
				if (($row['grants']['mail'] ?? null) !== ['read' => true]) {
					throw new \UnexpectedValueException('Unexpected mail.read default in the legacy matrix');
				}
				unset($row['grants']['mail']);
			}
			unset($row);
		}
		unset($state);
		return $answers;
	}
}
