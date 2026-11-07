<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

/**
 * The text of the cells of one page, kept whole within an explicit budget: valid UTF-8, without control characters
 * other than tab and line breaks and without direction-override characters, at most MAX_CELL_CHARS characters in one
 * cell and MAX_PAGE_CHARS in the whole page. Unlike the metadata ({@see \OCA\Mcp\Modules\Tables\Presentation\TablesText}), nothing
 * is cut in silence: a cell cut by either budget says so with the number of characters it had, and the page counts
 * the cut cells. The text stays untrusted data.
 */
final class RowCellText {
	/** Most characters of text returned in one cell. */
	public const MAX_CELL_CHARS = 20_000;

	/** Most characters of cell text returned in one page: the limit of files_read. */
	public const MAX_PAGE_CHARS = 100_000;

	/** Levels of nesting kept inside a cell value. */
	private const MAX_DEPTH = 3;

	/** Characters of the page budget still free. */
	private int $left = self::MAX_PAGE_CHARS;

	/** Cells cut so far. */
	private int $cut = 0;

	/**
	 * One cell: the value, and when text in it was cut, `truncated` and the characters it had (`totalChars`).
	 *
	 * @param int $columnId the column
	 * @param mixed $value the native value
	 * @return array{columnId:int, value:mixed, truncated?:true, totalChars?:int} the cell
	 */
	public function cell(int $columnId, mixed $value): array {
		$total = 0;
		$cellLeft = self::MAX_CELL_CHARS;
		$kept = $this->value($value, self::MAX_DEPTH, $total, $cellLeft);
		$cell = ['columnId' => $columnId, 'value' => $kept];
		if ($cellLeft < 0) {
			$this->cut++;
			$cell += ['truncated' => true, 'totalChars' => $total];
		}
		return $cell;
	}

	/**
	 * The cells cut in this page so far.
	 *
	 * @return int the count
	 */
	public function cutCells(): int {
		return $this->cut;
	}

	/**
	 * A value: scalars as they are, text cleaned and bounded, lists and maps of them to a depth; anything else as null.
	 *
	 * @param mixed $value the native value
	 * @param int $depth levels of nesting still allowed
	 * @param int $total characters of cleaned text seen in the cell, updated
	 * @param int $cellLeft characters the cell may still return, negative once something was cut
	 * @return mixed the value to return
	 */
	private function value(mixed $value, int $depth, int &$total, int &$cellLeft): mixed {
		if (is_string($value)) {
			$text = (string)preg_replace('/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', mb_scrub($value, 'UTF-8'));
			$length = mb_strlen($text);
			$total += $length;
			$room = max(0, min($cellLeft, $this->left));
			if ($length <= $room) {
				$cellLeft -= $length;
				$this->left -= $length;
				return $text;
			}
			$cellLeft = -1;
			$this->left -= $room;
			return mb_substr($text, 0, $room);
		}
		if (is_scalar($value) || $value === null) {
			return $value;
		}
		if (is_array($value) && $depth > 0) {
			$kept = [];
			foreach ($value as $key => $item) {
				$kept[$key] = $this->value($item, $depth - 1, $total, $cellLeft);
			}
			return $kept;
		}
		return null;
	}
}
