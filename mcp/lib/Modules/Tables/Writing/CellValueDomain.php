<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Tables\Writing;
/**
 * The value of one written cell in three forms: what the tool accepts, the argument handed to RowService, and the effect
 * the Tables parser of the installed release gives it (RowService::parseValueByColumnType()). The accepted values are the
 * ones the parser and the cell mapper behind it keep as asked: anything they would turn into null, '' or 0 in silence is
 * refused instead (an option that does not exist, a date in another format, a number outside the subtype's range or the
 * column's limits, null on yes/no, stars or progress, and null on a number before Tables 2.3.2, whose number mapper
 * stores 0 for it). Tables still does the conversion; nothing is written here.
 *
 * Users and groups are checked by {@see UsergroupWhitelist}; a relation only takes a row id here, after
 * {@see RelationWriteGate}. Nothing here calls the Tables app.
 */
class CellValueDomain {
	/** Longest text accepted in one cell. */
	public const MAX_TEXT = 100000;
	/**
	 * Receives the user and group check.
	 *
	 * @param UsergroupWhitelist $usergroups users, groups and teams a cell may name
	 * @return void
	 */
	public function __construct(
		private UsergroupWhitelist $usergroups,
	) {
	}
	/**
	 * The argument and the effect of one value.
	 *
	 * @param array<string, mixed> $column the column as Column::jsonSerialize() gives it
	 * @param mixed $value the value the tool was given
	 * @param string $version installed Tables version
	 * @param string $userId authenticated user UID
	 * @return array{argument: mixed, effect: mixed} what RowService receives and what its parser makes of it
	 * @throws RowWriteRefusal when the value does not fit the column
	 */
	public function cell(array $column, mixed $value, string $version, string $userId): array {
		$type = (string)($column['type'] ?? '');
		$subtype = (string)($column['subtype'] ?? '');
		return match ([$type, $subtype]) {
			['text', ''], ['text', 'line'], ['text', 'long'], ['text', 'rich'] => self::same(self::text($column, $value)),
			['text', 'link'] => self::same(LinkCellValue::link($column, $value)),
			['number', ''] => self::number($column, $value, $version),
			['number', 'stars'], ['number', 'progress'] => self::same(self::range($column, $value)),
			['selection', ''] => self::single($column, $value, $version),
			['selection', 'multi'] => self::multi($column, $value),
			['selection', 'check'] => is_bool($value) ? ['argument' => $value, 'effect' => $value ? 'true' : 'false'] : throw RowWriteRefusal::value(),
			['datetime', ''] => self::same(self::datetime($value, 'Y-m-d H:i')),
			['datetime', 'date'] => self::same(self::datetime($value, 'Y-m-d')),
			['datetime', 'time'] => self::same(self::datetime($value, 'H:i')),
			['usergroup', ''] => $value === null ? ['argument' => null, 'effect' => []]
				: self::same($this->usergroups->entries($column, $value, $version, $userId)),
			['relation', ''] => self::same(self::relation($value)),
			default => throw RowWriteRefusal::value(),
		};
	}
	/**
	 * Whether an effect leaves the cell empty, as RowService counts a mandatory column empty.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $effect the effect of {@see self::cell()}
	 * @return bool true for an empty cell
	 */
	public static function isEmpty(array $column, mixed $effect): bool {
		if (($column['type'] ?? '') === 'selection' && ($column['subtype'] ?? '') === 'check') {
			return false;
		}
		return $effect === null || $effect === '' || $effect === [];
	}
	/**
	 * Whether a link value is a JSON object with exactly title, value and providerId "url", an http(s) url and within the
	 * column's limits: the one form of link the parser and the cell mapper keep as it is.
	 *
	 * @param array<string, mixed> $column the column
	 * @param string $json the link as stored
	 * @return bool true when it is such a link
	 */
	public static function isCanonicalLink(array $column, string $json): bool {
		return LinkCellValue::isCanonicalLink($column, $json);
	}
	/**
	 * A value that is the same as argument and effect.
	 *
	 * @param mixed $value the value
	 * @return array{argument: mixed, effect: mixed} the forms
	 */
	private static function same(mixed $value): array {
		return ['argument' => $value, 'effect' => $value];
	}
	/**
	 * A text within the column's maximum length.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @return string|null the text, null to empty the cell
	 * @throws RowWriteRefusal for anything else
	 */
	private static function text(array $column, mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_string($value) || !self::fitsText($column, $value)) {
			throw RowWriteRefusal::value();
		}
		return $value;
	}
	/**
	 * A finite number within the column's limits ({@see NumberBounds}); null empties the cell only where the number mapper
	 * keeps it empty ({@see self::keepsEmptyNumbers()}).
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @param string $version installed Tables version
	 * @return array{argument: mixed, effect: mixed} the number, null to empty the cell
	 * @throws RowWriteRefusal for anything else
	 */
	private static function number(array $column, mixed $value, string $version): array {
		if ($value === null) {
			return self::keepsEmptyNumbers($version) ? self::same(null) : throw RowWriteRefusal::value();
		}
		if ((!is_int($value) && !is_float($value)) || !NumberBounds::fits($column, $value)) {
			throw RowWriteRefusal::value();
		}
		return ['argument' => $value, 'effect' => (float)$value];
	}
	/**
	 * Whether RowCellNumberMapper::applyDataToEntity() of a release keeps a missing number empty: 2.3.2 returns after
	 * storing null, the earlier pins go on to store (float)null, that is 0. A release between the pins is taken like the
	 * pin below it.
	 *
	 * @param string $version installed Tables version
	 * @return bool true when null stays empty
	 */
	public static function keepsEmptyNumbers(string $version): bool {
		return version_compare($version, '2.3.2', '>=');
	}
	/**
	 * Stars or progress: an integer within the subtype's range and the column's limits; null is refused, since the parser
	 * turns it into 0.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @return int the integer
	 * @throws RowWriteRefusal for anything else
	 */
	private static function range(array $column, mixed $value): int {
		if (!is_int($value) || !NumberBounds::fits($column, $value)) {
			throw RowWriteRefusal::value();
		}
		return $value;
	}
	/**
	 * An option of a single selection; the parser gives the id as a string or an integer by release
	 * ({@see self::parsesOptionAsString()}).
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @param string $version installed Tables version
	 * @return array{argument: mixed, effect: mixed} the option id, null to empty the cell
	 * @throws RowWriteRefusal for an id that is not an option
	 */
	private static function single(array $column, mixed $value, string $version): array {
		if ($value === null) {
			return self::same(null);
		}
		if (!is_int($value) || !in_array($value, self::optionIds($column), true)) {
			throw RowWriteRefusal::value();
		}
		return ['argument' => $value, 'effect' => self::parsesOptionAsString($version) ? (string)$value : $value];
	}

	/**
	 * Whether SelectionBusiness::parseValue() of a release gives the option id as a string: 1.0.10 and 2.2.2 on do, 1.0.0
	 * and 2.0.0 give an integer (2.0 branched before the 1.0.10 change). A release between the pins is taken like the pin
	 * below it.
	 *
	 * @param string $version installed Tables version
	 * @return bool true for a string
	 */
	public static function parsesOptionAsString(string $version): bool {
		return version_compare($version, '1.0.10', '>=')
			&& !(version_compare($version, '2.0.0', '>=') && version_compare($version, '2.2.2', '<'));
	}

	/**
	 * Options of a multiple selection; the parser sorts them and gives [] for null.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @return array{argument: mixed, effect: mixed} the option ids
	 * @throws RowWriteRefusal for a repeated id or an id that is not an option
	 */
	private static function multi(array $column, mixed $value): array {
		if ($value === null) {
			return ['argument' => null, 'effect' => []];
		}
		$options = self::optionIds($column);
		if (!is_array($value) || !array_is_list($value) || count($value) !== count(array_unique($value, SORT_REGULAR))) {
			throw RowWriteRefusal::value();
		}
		foreach ($value as $id) {
			if (!is_int($id) || !in_array($id, $options, true)) {
				throw RowWriteRefusal::value();
			}
		}
		$sorted = $value;
		sort($sorted, SORT_NUMERIC);
		return ['argument' => $value, 'effect' => $sorted];
	}

	/**
	 * A date, time or date and time in the one format the parser keeps.
	 *
	 * @param mixed $value the value
	 * @param string $format the format of the subtype
	 * @return string|null the value, null to empty the cell
	 * @throws RowWriteRefusal for anything else
	 */
	private static function datetime(mixed $value, string $format): ?string {
		if ($value === null) {
			return null;
		}
		if (!self::isExactDatetime($value, $format)) {
			throw RowWriteRefusal::value();
		}
		return $value;
	}

	/**
	 * Whether a value is a valid date, time or date and time in exactly one format.
	 *
	 * @param mixed $value the value
	 * @param string $format Y-m-d H:i, Y-m-d or H:i
	 * @return bool true when it is
	 */
	public static function isExactDatetime(mixed $value, string $format): bool {
		if (!is_string($value)) {
			return false;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
		return $parsed !== false && $parsed->format($format) === $value;
	}

	/**
	 * The id of a related row; whether it exists is the Tables app's to check, after {@see RelationWriteGate}.
	 *
	 * @param mixed $value the value
	 * @return int|null the id, null to empty the cell
	 * @throws RowWriteRefusal for anything else, a label included
	 */
	private static function relation(mixed $value): ?int {
		if ($value === null) {
			return null;
		}
		if (!is_int($value) || $value < 1) {
			throw RowWriteRefusal::value();
		}
		return $value;
	}

	/**
	 * The option ids of a selection column.
	 *
	 * @param array<string, mixed> $column the column
	 * @return list<int> the ids
	 */
	public static function optionIds(array $column): array {
		$ids = [];
		foreach ((array)($column['selectionOptions'] ?? []) as $option) {
			if (is_array($option) && isset($option['id']) && is_numeric($option['id'])) {
				$ids[] = (int)$option['id'];
			}
		}
		return $ids;
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
