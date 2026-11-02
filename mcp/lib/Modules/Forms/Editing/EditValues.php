<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\L10n\Translator;
/** Validates byte-faithful values and canonical internal state without silently repairing or shortening text. */
final class EditValues
{
	/** Refuses a fixed declared field without reflecting hostile input.
	 * @param string $field Declared field path.
	 * @return never always throws the fixed field validation error
	 */
	public static function invalid(string $field): never
	{
		throw new ArgumentValidationException('Invalid argument: ' . $field, $field, Translator::t('must match the supported Forms editing schema'));
	}
	/** Validates complete Unicode text within both declared character and byte limits.
	 * @param mixed $value Submitted or native string.
	 * @param int $min Minimum code points.
	 * @param int $max Maximum code points.
	 * @param string $field Fixed path.
	 * @return string Unchanged bytes.
	 */
	public static function text(mixed $value, int $min, int $max, string $field): string
	{
		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\x00") || strlen($value) > $max * 4 || mb_strlen($value, 'UTF-8') < $min || mb_strlen($value, 'UTF-8') > $max || $min > 0 && preg_match('/[^\p{Z}\s\p{Cf}]/u', $value) !== 1) {
			self::invalid($field);
		}
		return $value;
	}
	/** Requires a closed object without coercion.
	 * @param mixed $value Candidate object.
	 * @param list<string> $keys Allowed fields.
	 * @param string $field Fixed path.
	 * @return array<string,mixed> Original object.
	 */
	public static function object(mixed $value, array $keys, string $field): array
	{
		if (!is_array($value) || $value !== [] && array_is_list($value) || array_diff(array_keys($value), $keys) !== []) {
			self::invalid($field);
		}
		return $value;
	}
	/** Encodes complete data strictly, refusing invalid native UTF-8 before digest/projection.
	 * @param mixed $value Complete state.
	 * @return string Strict JSON.
	 */
	public static function json(mixed $value): string
	{
		return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	/** Sorts object keys only, preserving meaningful list order.
	 * @param mixed $value State or value.
	 * @return mixed Canonical state.
	 */
	public static function canonical(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		if (!array_is_list($value)) {
			ksort($value);
		}
		foreach ($value as &$item) {
			$item = self::canonical($item);
		}
		unset($item);
		return $value;
	}
}
