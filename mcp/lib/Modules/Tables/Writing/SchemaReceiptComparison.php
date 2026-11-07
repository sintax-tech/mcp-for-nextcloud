<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/** Compares native receipts without treating map insertion order or native float normalization as changes. */
final class SchemaReceiptComparison {
	/**
	 * @param array<string,mixed> $expected Requested native values.
	 * @param array<string,mixed> $observed Fresh authorized values.
	 * @return list<string> Fields that actually differ.
	 */
	public static function differences(array $expected, array $observed): array {
		$fields = [];
		foreach ($expected as $field => $value) {
			if (!array_key_exists($field, $observed) || !self::same($value, $observed[$field], $field)) $fields[] = $field;
		}
		if ($expected === [] && $observed !== []) $fields[] = 'share';
		if (($observed['viewsVerified'] ?? true) === false) $fields[] = 'views';
		return $fields;
	}
	/**
	 * @param mixed $expected Native expected value.
	 * @param mixed $actual Native observed value.
	 * @param string $field Native field name for numeric DTO normalization.
	 * @return bool Equal maps, ordered lists and strict values except the proven native float fields.
	 */
	public static function same(mixed $expected, mixed $actual, string $field = ''): bool {
		if (in_array($field, ['numberDefault', 'numberMin', 'numberMax'], true)
			&& (is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
			return (float)$expected === (float)$actual;
		}
		if (!is_array($expected) || !is_array($actual)) return $expected === $actual;
		if (count($expected) !== count($actual) || array_is_list($expected) !== array_is_list($actual)) return false;
		foreach ($expected as $key => $value) {
			if (!array_key_exists($key, $actual) || !self::same($value, $actual[$key], (string)$key)) return false;
		}
		return true;
	}
}
