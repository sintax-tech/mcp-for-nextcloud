<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

/** Minimal oversized post-fetch port; an out-of-budget map must be rejected before looking at its members. */
final class EditOversizedReader {
	/** Captures an otherwise admissible member solely to build an oversized list.
	 * @param EditingRow $row Unit entity.
	 */
	public function __construct(private EditingRow $row) { }
	/** Supplies 201 question entries to discriminate the complete-map limit.
	 * @param int $id Admitted form.
	 * @return list<EditingRow> Oversized materialized list.
	 */
	public function findByForm(int $id):array{return array_fill(0,201,$this->row);}
	/** Supplies 201 option entries to discriminate the complete-map limit.
	 * @param int $id Admitted question.
	 * @return list<EditingRow> Oversized materialized list.
	 */
	public function findByQuestion(int $id):array{return array_fill(0,201,$this->row);}
}
