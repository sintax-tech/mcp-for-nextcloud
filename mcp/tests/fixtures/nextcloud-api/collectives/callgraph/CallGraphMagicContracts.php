<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** A literal magic method name retains its concrete class's declared return or field contract. */
final class CallGraphMagicContracts {
	/**
	 * @param CallGraphIndex $index pinned declarations
	 * @param string $class concrete receiver
	 * @param string $method literal method name supplied to __call
	 * @return string|null the declared contract; an absent or untyped field never acquires a type from its name
	 */
	public static function result(CallGraphIndex $index, string $class, string $method): ?string {
		$hierarchy = [$class, ...$index->ancestors($class)];
		foreach ($hierarchy as $owner) {
			$contracts = $index->classes[$owner]['magicReturns'] ?? [];
			if (array_key_exists(strtolower($method), $contracts)) {
				return $contracts[strtolower($method)];
			}
		}
		$value = CallGraphMagicFields::call($index, $class, '__call', [['literal' => $method], ['type' => '[]']]);
		return $value['type'] ?? (isset($value['literal']) ? '' : null);
	}
}
