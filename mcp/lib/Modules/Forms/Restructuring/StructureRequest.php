<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditValues;

/** Closed validation shared by the structure tools: keys, confirmation flags, IDs, ID lists and payload size. */
final class StructureRequest {
	/** Confirmation keys every structure tool accepts besides its own arguments. */
	private const CONFIRMATION = ['confirm', 'confirm_shared', 'plan_state'];
	/** Largest accepted request, in bytes of canonical JSON. */
	private const MAX_BYTES = 65536;

	private function __construct() {
	}

	/**
	 * @param array<string,mixed> $args submitted arguments
	 * @param list<string> $keys tool arguments, form_id included
	 * @param list<string> $ids keys that must hold a positive integer
	 * @return array<string,mixed> the integer IDs, keyed by name
	 */
	public static function of(array $args, array $keys, array $ids): array {
		EditValues::object($args, [...$keys, ...self::CONFIRMATION], 'arguments');
		foreach (['confirm', 'confirm_shared'] as $key) {
			if (array_key_exists($key, $args) && !is_bool($args[$key])) {
				EditValues::invalid($key);
			}
		}
		$request = [];
		foreach ($ids as $key) {
			$request[$key] = self::id($args[$key] ?? null, $key);
		}
		return $request;
	}

	/**
	 * @param mixed $value candidate
	 * @param string $field argument name
	 * @return int positive integer, without coercion
	 */
	public static function id(mixed $value, string $field): int {
		if (!is_int($value) || $value < 1) {
			EditValues::invalid($field);
		}
		return $value;
	}

	/**
	 * @param mixed $value candidate list
	 * @param string $field argument name
	 * @return list<int> 1 to 200 distinct positive integers, in the given order
	 */
	public static function ids(mixed $value, string $field): array {
		if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 200) {
			EditValues::invalid($field);
		}
		foreach ($value as $id) {
			self::id($id, $field);
		}
		if (count(array_unique($value)) !== count($value)) {
			EditValues::invalid($field);
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $request validated request
	 * @return array<string,mixed> the same request, canonical
	 */
	public static function bounded(array $request): array {
		if (strlen(EditValues::json($request)) > self::MAX_BYTES) {
			EditValues::invalid('arguments');
		}
		return EditValues::canonical($request);
	}
}
