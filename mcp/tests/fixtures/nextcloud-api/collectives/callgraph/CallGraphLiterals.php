<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Scalar property defaults prove their value types without evaluating upstream code. */
final class CallGraphLiterals {
	/**
	 * @param Expr|null $value a property default
	 * @return string|null '' for a scalar, scalar[] for a scalar literal array, unknown otherwise
	 */
	public static function type(?Expr $value): ?string {
		if ($value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_) {
			return $value instanceof Node\Scalar\Int_ ? '@number:int' : '@number:float';
		}
		if ($value instanceof Node\Scalar || ($value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true))) {
			return '';
		}
		if ($value instanceof Expr\Array_) {
			$values = $shape = $layout = [];
			$exact = true;
			foreach ($value->items as $position => $item) {
				if ($item === null || $item->unpack || ($type = self::type($item->value)) === null) {
					return CallGraphArrayTypes::make([''], [null]);
				}
				$values[] = $type;
				$key = $item->key === null ? null : ($item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_ ? $item->key->value : null);
				if ($item->key === null && $exact) { $layout[] = true; $key = array_key_last($layout); } elseif ($key !== null) { $layout[$key] = true; $key = array_key_last($layout); }
				if ($key === null) { $exact = false; }
				if ($key !== null) {
					$shape[(string)$key] = [$type];
				}
			}
			return $value->items === [] ? '[]' : CallGraphArrayTypes::make([''], $values, $exact ? $shape : []);
		}
		return null;
	}
}
