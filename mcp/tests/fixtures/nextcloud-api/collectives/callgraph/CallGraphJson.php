<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** JSON cannot instantiate user classes: its recursive values contain only scalars, arrays and possibly exact stdClass. */
final class CallGraphJson {
	/**
	 * @param Expr\FuncCall $call json_decode arguments
	 * @return list<string> recursive scalar/array values, or the union also containing exact stdClass
	 */
	public static function type(Expr\FuncCall $call): array {
		$assoc = $call->args[1]->value ?? null;
		if ($assoc instanceof Expr\ConstFetch && strtolower($assoc->name->toString()) === 'true') {
			return ['json-value'];
		}
		if ($assoc instanceof Expr\ConstFetch && strtolower($assoc->name->toString()) === 'false') {
			return ['json-object-value'];
		}
		if ($assoc !== null && !($assoc instanceof Expr\ConstFetch && strtolower($assoc->name->toString()) === 'null')) {
			return ['json-value', 'json-object-value'];
		}
		$flags = isset($call->args[3]) ? self::flags($call->args[3]->value) : 0;
		return $flags === null ? ['json-value', 'json-object-value']
			: [($flags & JSON_OBJECT_AS_ARRAY) !== 0 ? 'json-value' : 'json-object-value'];
	}

	/**
	 * @param Expr $value a flag expression
	 * @return int|null its literal JSON flag mask, or unknown; no upstream code is evaluated
	 */
	private static function flags(Expr $value): ?int {
		if ($value instanceof Node\Scalar\Int_) {
			return $value->value;
		}
		if ($value instanceof Expr\ConstFetch && str_starts_with($value->name->toString(), 'JSON_') && defined($value->name->toString())) {
			$constant = constant($value->name->toString());
			return is_int($constant) ? $constant : null;
		}
		if ($value instanceof Expr\BinaryOp\BitwiseOr) {
			$left = self::flags($value->left);
			$right = self::flags($value->right);
			return $left === null || $right === null ? null : $left | $right;
		}
		return null;
	}
}
