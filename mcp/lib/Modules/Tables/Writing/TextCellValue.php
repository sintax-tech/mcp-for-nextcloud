<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/** The accepted plain text values and length limits shared by text and link cells. */
final class TextCellValue {
	/** Longest text accepted in one cell. */
	public const MAX_TEXT = 100000;

	/** Prevents construction of this static text value boundary. */
	private function __construct() {
	}

	/**
	 * A text within the column's maximum length.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @return string|null the text, null to empty the cell
	 * @throws RowWriteRefusal for anything else
	 */
	public static function text(array $column, mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_string($value) || !self::fitsText($column, $value)) {
			throw RowWriteRefusal::value();
		}
		return $value;
	}

	/**
	 * Whether a text fits the column's maximum length, as RowService checks it when the column has one, and the tool's.
	 *
	 * @param array<string, mixed> $column the column
	 * @param string $text the text
	 * @return bool true when it fits
	 */
	public static function fitsText(array $column, string $text): bool {
		$length = mb_strlen($text);
		$max = $column['textMaxLength'] ?? null;
		return $length <= self::MAX_TEXT && (!is_numeric($max) || $length <= (int)$max);
	}
}
