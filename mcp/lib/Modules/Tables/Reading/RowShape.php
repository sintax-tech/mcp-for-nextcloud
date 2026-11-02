<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

use OCA\Mcp\Modules\Tables\Presentation\TablesText;

/**
 * What a row looks like in an answer, from Row2::jsonSerialize() (the shape RowService::formatRows() returns): id, the
 * native authorship and dates, and the cells as {columnId, value} of the columns the user can see only, with the
 * native value (a selection gives option ids, a relation the related row id; nothing is resolved). A cell Tables did not
 * store is absent, as in the native answer: no default is made up. The text of a cell is cleaned and bounded by
 * {@see RowCellText}, which flags a cut cell; the authorship is cleaned like the other metadata. Everything stays
 * untrusted; the alias map (`dataByAlias`) is left out, the cells carry the same values by id.
 */
final class RowShape {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * One row.
	 *
	 * @param object $row OCA\Tables\Db\Row2 read for the user
	 * @param array<int, true> $visible ids of the columns the user can see
	 * @param RowCellText $text the text budget of the page
	 * @return array{id:int, createdBy:string, createdAt:string, lastEditBy:string, lastEditAt:string, cells:list<array{columnId:int, value:mixed, truncated?:true, totalChars?:int}>} the row
	 */
	public static function of(object $row, array $visible, RowCellText $text): array {
		$data = (array)$row->jsonSerialize();
		$cells = [];
		foreach ((array)($data['data'] ?? []) as $cell) {
			$columnId = is_array($cell) ? (int)($cell['columnId'] ?? 0) : 0;
			if (isset($visible[$columnId])) {
				$cells[] = $text->cell($columnId, $cell['value'] ?? null);
			}
		}
		return [
			'id' => (int)($data['id'] ?? 0),
			'createdBy' => TablesText::clean($data['createdBy'] ?? ''),
			'createdAt' => TablesText::clean($data['createdAt'] ?? ''),
			'lastEditBy' => TablesText::clean($data['lastEditBy'] ?? ''),
			'lastEditAt' => TablesText::clean($data['lastEditAt'] ?? ''),
			'cells' => $cells,
		];
	}
}
