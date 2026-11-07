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
		if ($value instanceof Node\Scalar || ($value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true))) {
			return '';
		}
		if ($value instanceof Expr\Array_ && $value->items !== []) {
			foreach ($value->items as $item) {
				if ($item === null || $item->unpack || self::type($item->value) !== '') {
					return null;
				}
			}
			return 'scalar[]';
		}
		return null;
	}
}
