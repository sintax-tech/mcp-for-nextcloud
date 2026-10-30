<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

/**
 * What a user condition may be, per column type and subtype, operator, value and installed Tables version, and its
 * native form ({columnId, operator, value} with the value as the string the engine expects). It is a smaller surface
 * than what the engine accepts on purpose: anything outside it is refused before the engine. A value starting with `@`
 * is a Tables placeholder (`ColumnsHelper::resolveSearchValue()`), so only the placeholders of the type and the release
 * are accepted and any other `@` value is refused: the engine has no escape for it. The releases a feature first appears
 * in are the pinned ones it was observed in (tests/fixtures/nextcloud-api/tables/tables-behavior.json), so a release in
 * between is treated as not having it.
 */
final class RowFilterDomain {
	/** Meta columns: id, created by, edited by, created at, edited at. */
	public const META_COLUMNS = [-1, -2, -3, -4, -5];

	/** Operators of text columns. */
	private const TEXT = ['begins-with', 'ends-with', 'contains', 'does-not-contain', 'is-equal', 'is-not-equal', 'is-empty'];

	/** Operators of number and datetime columns. */
	private const ORDERED = ['is-equal', 'is-not-equal', 'is-greater-than', 'is-greater-than-or-equal', 'is-lower-than', 'is-lower-than-or-equal', 'is-empty'];

	/** Operators of a single selection. */
	private const SELECTION_SINGLE = ['is-equal', 'is-not-equal', 'is-empty'];

	/** Operators of a multiple selection. */
	private const SELECTION_MULTI = ['contains', 'does-not-contain', 'is-equal', 'is-empty'];

	/** Operators of a user or group column. */
	private const USERGROUP = ['contains', 'does-not-contain', 'is-empty'];

	/** Operators of a relation column. */
	private const RELATION = ['is-equal', 'is-not-equal', 'is-empty'];

	/** Operators of the meta columns of ids and dates. */
	private const META_ORDERED = ['is-equal', 'is-not-equal', 'is-greater-than', 'is-greater-than-or-equal', 'is-lower-than', 'is-lower-than-or-equal'];

	/** Datetime placeholders of every supported release. */
	private const DATETIME_PLACEHOLDERS = ['@datetime-date-today', '@datetime-date-start-of-year', '@datetime-date-start-of-month',
		'@datetime-date-start-of-week', '@datetime-time-now', '@datetime-now'];

	/** Datetime placeholders with an argument, from 1.0.10 on: name and the pattern of the argument. */
	private const DATETIME_ARGUMENT_PLACEHOLDERS = ['@datetime-exact-date' => '/^\d{4}-\d{2}-\d{2}$/', '@datetime-days-ahead' => '/^\d{1,4}$/',
		'@datetime-days-ago' => '/^\d{1,4}$/'];

	/** First pinned release with is-not-empty. */
	public const IS_NOT_EMPTY_SINCE = '2.3.2';

	/** First pinned release with the datetime placeholders that take an argument. */
	public const DATETIME_ARGUMENTS_SINCE = '1.0.10';

	/** First pinned release with relation columns. */
	public const RELATION_SINCE = '2.2.2';

	/**
	 * Receives the installed Tables version.
	 *
	 * @param string $version installed Tables version
	 * @return void
	 */
	public function __construct(
		private string $version,
	) {
	}

	/**
	 * The native form of a user condition.
	 *
	 * @param int $columnId column id, or a meta column
	 * @param array<string, mixed>|null $column the serialized visible column, null for a meta column
	 * @param string $operator the operator
	 * @param mixed $value the value, absent for is-empty and is-not-empty
	 * @return array{columnId:int, operator:string, value:string} the native condition
	 * @throws RowsRefusal when the condition is outside the domain
	 */
	public function condition(int $columnId, ?array $column, string $operator, mixed $value): array {
		if (in_array($operator, ['is-empty', 'is-not-empty'], true)) {
			if (!in_array($operator, $this->operators($columnId, $column), true) || ($value !== null && $value !== '')) {
				throw RowsRefusal::invalidQuery();
			}
			return ['columnId' => $columnId, 'operator' => $operator, 'value' => ''];
		}
		if (!in_array($operator, $this->operators($columnId, $column), true)) {
			throw RowsRefusal::invalidQuery();
		}
		return ['columnId' => $columnId, 'operator' => $operator, 'value' => $this->value($columnId, $column, $value)];
	}

	/**
	 * The operators of a column in the installed release.
	 *
	 * @param int $columnId column id, or a meta column
	 * @param array<string, mixed>|null $column the serialized visible column, null for a meta column
	 * @return list<string> the operators
	 */
	public function operators(int $columnId, ?array $column): array {
		if ($column === null) {
			return match ($columnId) {
				-1, -4, -5 => self::META_ORDERED,
				-2, -3 => ['is-equal'],
				default => [],
			};
		}
		$withNotEmpty = static fn (array $operators): array => [...$operators, 'is-not-empty'];
		$notEmpty = version_compare($this->version, self::IS_NOT_EMPTY_SINCE, '>=');
		$operators = match ([(string)($column['type'] ?? ''), self::subtype($column)]) {
			['text', 'line'], ['text', 'long'], ['text', 'rich'], ['text', 'link'], ['text', ''] => self::TEXT,
			['number', ''], ['number', 'stars'], ['number', 'progress'], ['datetime', ''], ['datetime', 'date'], ['datetime', 'time'] => self::ORDERED,
			['selection', ''] => self::SELECTION_SINGLE,
			['selection', 'multi'] => self::SELECTION_MULTI,
			['selection', 'check'] => ['is-equal'],
			['usergroup', ''] => self::USERGROUP,
			['relation', ''] => version_compare($this->version, self::RELATION_SINCE, '>=') ? self::RELATION : [],
			default => [],
		};
		$extendable = in_array((string)($column['type'] ?? ''), ['text', 'number', 'datetime'], true);
		return $notEmpty && $extendable ? $withNotEmpty($operators) : $operators;
	}

	/**
	 * The native value of a condition.
	 *
	 * @param int $columnId column id, or a meta column
	 * @param array<string, mixed>|null $column the serialized visible column, null for a meta column
	 * @param mixed $value the value the user gave
	 * @return string the value the engine expects
	 * @throws RowsRefusal when the value does not fit the column
	 */
	private function value(int $columnId, ?array $column, mixed $value): string {
		if ($column === null) {
			return match ($columnId) {
				-1 => self::integer($value),
				-4, -5 => self::isoDatetime($value),
				default => self::plainText($value),
			};
		}
		return match ([(string)$column['type'], self::subtype($column)]) {
			['number', ''], ['number', 'stars'], ['number', 'progress'] => self::number($value),
			['datetime', ''], ['datetime', 'date'], ['datetime', 'time'] => $this->datetime($value),
			['selection', ''], ['selection', 'multi'] => self::optionId($column, $value),
			['selection', 'check'] => in_array($value, ['@checked', '@unchecked'], true) ? (string)$value : throw RowsRefusal::invalidQuery(),
			['usergroup', ''] => $value === '@me' ? '@me' : self::plainText($value),
			['relation', ''] => self::integer($value),
			default => self::plainText($value),
		};
	}

	/**
	 * The subtype of a column, '' when it has none.
	 *
	 * @param array<string, mixed> $column the serialized column
	 * @return string the subtype
	 */
	public static function subtype(array $column): string {
		return (string)($column['subtype'] ?? '');
	}

	/**
	 * A text value: a non-empty string of one line that is no placeholder.
	 *
	 * @param mixed $value the value
	 * @return string the text
	 * @throws RowsRefusal for anything else
	 */
	private static function plainText(mixed $value): string {
		if (!is_string($value) || $value === '' || str_starts_with($value, '@') || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			throw RowsRefusal::invalidQuery();
		}
		return $value;
	}

	/**
	 * An integer value.
	 *
	 * @param mixed $value the value
	 * @return string the integer as a string
	 * @throws RowsRefusal for anything else
	 */
	private static function integer(mixed $value): string {
		if (is_int($value) && $value >= 0) {
			return (string)$value;
		}
		if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
			return $value;
		}
		throw RowsRefusal::invalidQuery();
	}

	/**
	 * A number value.
	 *
	 * @param mixed $value the value
	 * @return string the number as a string
	 * @throws RowsRefusal for anything else
	 */
	private static function number(mixed $value): string {
		if ((is_int($value) || is_float($value)) && is_finite((float)$value)) {
			return (string)$value;
		}
		if (is_string($value) && preg_match('/^-?\d{1,15}(\.\d{1,10})?$/', $value) === 1) {
			return $value;
		}
		throw RowsRefusal::invalidQuery();
	}

	/**
	 * A datetime value: an ISO date, time or date and time, or a datetime placeholder of the installed release.
	 *
	 * @param mixed $value the value
	 * @return string the value
	 * @throws RowsRefusal for anything else
	 */
	private function datetime(mixed $value): string {
		if (!is_string($value)) {
			throw RowsRefusal::invalidQuery();
		}
		if (in_array($value, self::DATETIME_PLACEHOLDERS, true)) {
			return $value;
		}
		if (str_starts_with($value, '@')) {
			[$name, $argument] = explode(':', $value, 2) + [1 => null];
			$pattern = self::DATETIME_ARGUMENT_PLACEHOLDERS[$name] ?? null;
			if ($pattern !== null && $argument !== null && preg_match($pattern, $argument) === 1
				&& version_compare($this->version, self::DATETIME_ARGUMENTS_SINCE, '>=')) {
				return $value;
			}
			throw RowsRefusal::invalidQuery();
		}
		return self::isoDatetime($value);
	}

	/**
	 * An ISO date, time or date and time.
	 *
	 * @param mixed $value the value
	 * @return string the value
	 * @throws RowsRefusal for anything else
	 */
	private static function isoDatetime(mixed $value): string {
		if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?|\d{2}:\d{2})$/', $value) === 1) {
			return $value;
		}
		throw RowsRefusal::invalidQuery();
	}

	/**
	 * The id of an option of a selection column.
	 *
	 * @param array<string, mixed> $column the serialized column
	 * @param mixed $value the option id
	 * @return string the id as a string
	 * @throws RowsRefusal when the column has no such option
	 */
	private static function optionId(array $column, mixed $value): string {
		$id = self::integer($value);
		$ids = array_map(static fn (mixed $option): string => is_array($option) ? (string)($option['id'] ?? '') : '', (array)($column['selectionOptions'] ?? []));
		if (!in_array($id, $ids, true)) {
			throw RowsRefusal::invalidQuery();
		}
		return $id;
	}
}
