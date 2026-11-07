<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Structural array evidence keeps keys, values and literal fields separate; null always means an unproved value. */
final class CallGraphArrayTypes {
	/** @var array<string,array> canonical immutable descriptors, shared within this PHP process */
	private static array $arrays = [];
	/**
	 * @param list<?string> $keys possible key types
	 * @param list<?string> $values possible value types
	 * @param array<string,list<?string>> $shape literal fields, when known
	 * @return string canonical array domain, preserving the legacy [] suffix for container checks
	 */
	public static function make(array $keys, array $values, array $shape = []): string {
		ksort($shape);
		$data = ['keys' => self::members($keys), 'values' => self::members($values), 'shape' => $shape];
		$type = '@array:' . hash('sha256', serialize($data)) . '[]';
		self::$arrays[$type] = $data;
		return $type;
	}

	/**
	 * @param string|null $type an encoded or legacy array type
	 * @return array|null its key/value/field evidence, never inferred for a bare unknown
	 */
	public static function read(?string $type): ?array {
		if ($type === null || !str_ends_with($type, '[]')) {
			return null;
		}
		if (isset(self::$arrays[$type])) { return self::$arrays[$type]; }
		return ['keys' => [''], 'values' => $type === '[]' ? [] : [$type === 'scalar[]' ? '' : substr($type, 0, -2)], 'shape' => []];
	}

	/**
	 * @param list<?string> $types alternatives, possibly encoded as one return/field type
	 * @return list<?string> flattened, strictly deduplicated alternatives
	 */
	public static function members(array $types): array {
		$result = [];
		foreach ($types as $type) {
			foreach (is_string($type) && str_starts_with($type, '@union:') ? json_decode(substr($type, 7), true, 512, JSON_THROW_ON_ERROR) : [$type] as $member) {
				$result[serialize($member)] = $member;
			}
		}
		if (count($result) > 1) { unset($result[serialize('@bottom')]); }
		ksort($result);
		return array_values($result);
	}

	/**
	 * @param list<?string> $types alternatives
	 * @return string|null a single representable union; no unknown member is discarded
	 */
	public static function pack(array $types): ?string {
		$types = self::members($types);
		return $types === [] ? '@bottom' : (count($types) === 1 ? $types[0] : '@union:' . json_encode($types, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param list<?string> $types possible expression types
	 * @return list<?string> flattened union, merging structural arrays by field and keeping non-array alternatives
	 */
	public static function merge(array $types): array {
		$types = self::members($types);
		if (count($types) > 1) { $types = array_values(array_filter($types, static fn (?string $t): bool => $t !== '@bottom')); }
		$arrays = array_values(array_filter($types, static fn (?string $t): bool => self::read($t) !== null));
		if (count($arrays) < 2) {
			return $types;
		}
		$keys = $values = $shape = [];
		$exact = true;
		foreach ($arrays as $type) {
			$array = self::read($type);
			array_push($keys, ...$array['keys']);
			array_push($values, ...$array['values']);
			$exact = $exact && ($type === '[]' || $array['shape'] !== []);
			foreach ($array['shape'] as $key => $value) {
				$shape[$key] = self::members([...($shape[$key] ?? []), ...$value]);
			}
		}
		return self::members([...array_filter($types, static fn (?string $t): bool => self::read($t) === null), self::make($keys, $values, $exact ? $shape : [])]);
	}

	/**
	 * @param string|null $type array/recursive JSON/scalar-string evidence
	 * @param string|int|null $key literal offset, or null for an unspecified key
	 * @return list<?string> possible values of the selected key
	 */
	public static function values(?string $type, string|int|null $key = null): array {
		$values = [];
		foreach (self::members([$type]) as $member) {
			$array = self::read($member);
			if ($array !== null) {
				array_push($values, ...($key !== null && $array['shape'] !== [] ? ($array['shape'][(string)$key] ?? ['']) : ($array['values'] === [] ? [''] : $array['values'])));
			} else {
				$values[] = in_array($member, ['json-value', 'json-object-value', '', '@bottom'], true) ? $member : null;
			}
		}
		return self::members($values);
	}

	/**
	 * @param list<?string> $types arrays/unions potentially containing objects
	 * @return list<?string> every leaf value; an unknown array element remains unknown
	 */
	public static function leaves(array $types): array {
		$result = [];
		foreach (self::members($types) as $type) {
			$array = self::read($type);
			array_push($result, ...($array === null ? [$type] : self::leaves($array['values'])));
		}
		return self::members($result);
	}
}
