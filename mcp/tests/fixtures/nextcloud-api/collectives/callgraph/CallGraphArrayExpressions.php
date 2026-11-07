<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Literal array construction follows PHP's key normalization and next-index rules. */
final class CallGraphArrayExpressions {
	/**
	 * @param string|null $class lexical class
	 * @param Expr\Array_ $expr literal array
	 * @param array<string,list<?string>> $locals local values
	 * @param CallGraphTypes $inference expression inference
	 * @return list<?string> complete field/value evidence, or an unknown element for unsupported construction
	 */
	public static function literal(?string $class, Expr\Array_ $expr, array $locals, CallGraphTypes $inference): array {
		$keys = $values = $shape = [];
		$exact = true;
		$layout = [];
		foreach ($expr->items as $position => $item) {
			if ($item === null || $item->unpack) {
				return [CallGraphArrayTypes::make([''], [null])];
			}
			$types = $inference->typeOf($class, $item->value, $locals);
			array_push($values, ...$types);
			array_push($keys, ...($item->key === null ? [''] : $inference->typeOf($class, $item->key, $locals)));
			$key = $item->key === null ? null : ($item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_ ? $item->key->value : null);
			if ($item->key === null && $exact) { $layout[] = true; $key = array_key_last($layout); } elseif ($key !== null) { $layout[$key] = true; $key = array_key_last($layout); }
			if ($key === null) {
				$exact = false;
				continue;
			}
			$shape[(string)$key] = $types;
		}
		return $expr->items === [] ? ['[]'] : [CallGraphArrayTypes::make($keys, $values, $exact ? $shape : [])];
	}
}
