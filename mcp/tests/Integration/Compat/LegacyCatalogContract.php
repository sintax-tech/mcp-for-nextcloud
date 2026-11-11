<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Keeps the complete legacy operation matrix, explicitly allowing only the documented 1.2.2 and approved 1.3.0 F00 and Collectives C01–C04 and denied 1.4.0 routine additions. */
final class LegacyCatalogContract {
	/**
	 * @param array<string, list<string>> $baseline catalog actually read from the released 1.0.8 code
	 * @param array<string, list<string>> $current candidate catalog
	 * @return array<string, list<string>> unchanged baseline operation matrix for before/after comparisons
	 * @throws \UnexpectedValueException on removal, changed operations or an unrecognized addition
	 */
	public static function baseline(array $baseline, array $current): array {
		$expected = $baseline;
		$expected['files'][] = 'delete';
		$expected += [
			'mail' => ['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'],
			'tables' => ['read', 'create', 'edit', 'schema', 'share'],
			'forms' => ['read', 'results', 'create', 'edit'],
			'polls' => ['read', 'results', 'links', 'create', 'configure', 'options', 'options.remove', 'share', 'invite'],
			// C01–C04: each reviewed operation is explicit and every write starts denied.
			'collectives' => ['read', 'create', 'edit', 'move', 'collective.create', 'members'],
		];
		$expected = RoutineCatalogAdditions::catalog($expected);
		if (RoutineCatalogAdditions::overlaps($baseline) || array_intersect(['mail', 'tables', 'forms', 'polls', 'collectives'], array_keys($baseline)) !== []
			|| in_array('delete', $baseline['files'], true) || $current !== $expected) {
			throw new \UnexpectedValueException('Legacy catalog changed beyond the documented 1.2.2 and approved 1.3.0 F00, Collectives C01–C04 and denied 1.4.0 routine additions');
		}
		return $baseline;
	}

	/**
	 * @param array<string, array<string, mixed>> $answers branch observations over the complete legacy matrix
	 * @return array<string, array<string, mixed>> all legacy observations unchanged after checking every newly introduced matrix cell
	 * @throws \UnexpectedValueException if any new operation has an unexpected default
	 */
	public static function answers(array $answers): array {
		foreach ($answers as &$state) {
			foreach ($state['matrix'] as &$row) {
				$row['grants'] = RoutineCatalogAdditions::previous($row['grants']);
				if (($row['grants']['files']['delete'] ?? null) !== false) {
					throw new \UnexpectedValueException('Unexpected files.delete default in the legacy matrix');
				}
				unset($row['grants']['files']['delete']);
				foreach ([
					'mail' => ['read' => true, 'messages' => false, 'attachments' => false, 'flags' => false, 'move' => false, 'draft' => false, 'send' => false],
					'tables' => ['read' => true, 'create' => false, 'edit' => false, 'schema' => false, 'share' => false],
					'forms' => ['read' => true, 'results' => false, 'create' => false, 'edit' => false],
					'polls' => ['read' => true, 'results' => false, 'links' => false, 'create' => false, 'configure' => false, 'options' => false, 'options.remove' => false, 'share' => false, 'invite' => false],
					'collectives' => ['read' => true, 'create' => false, 'edit' => false, 'move' => false, 'collective.create' => false, 'members' => false],
				] as $module => $defaults) {
					if (($row['grants'][$module] ?? null) !== $defaults) {
						throw new \UnexpectedValueException("Unexpected $module defaults in the legacy matrix");
					}
					unset($row['grants'][$module]);
				}
			}
			unset($row);
		}
		unset($state);
		return $answers;
	}
}
