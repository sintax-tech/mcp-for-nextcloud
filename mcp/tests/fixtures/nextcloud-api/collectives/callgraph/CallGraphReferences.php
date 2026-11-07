<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Explicit internal out-parameter facts, with flag-dependent tuples and no scalar default for an unmodelled function. */
final class CallGraphReferences {
	/**
	 * @param Expr\FuncCall $call internal function invocation
	 * @param CallGraphIndex $index function resolution
	 * @return array<string,list<?string>> plain local out-parameters and their possible assigned types
	 */
	public static function outputs(Expr\FuncCall $call, CallGraphIndex $index): array {
		$name = $call->name instanceof Node\Name ? strtolower($index->functionName($call->name)) : '';
		$model = CallGraphFunctions::model($name);
		$result = [];
		foreach ($model['outArguments'] ?? [] as $position => $kind) {
			$target = $call->args[$position]->value ?? null;
			if (!$target instanceof Expr\Variable || !is_string($target->name)) { continue; }
			$flags = isset($call->args[3]) ? self::flags($call->args[3]->value) : 0;
			$text = ['']; // Matches contain strings; PREG_UNMATCHED_AS_NULL adds null, never an object.
			$tuple = CallGraphArrayTypes::make(['@number:int'], ['', '@number:int'], ['0' => [''], '1' => ['@number:int']]);
			$value = $flags === null ? ['', $tuple] : (($flags & PREG_OFFSET_CAPTURE) !== 0 ? [$tuple] : $text);
			$row = CallGraphArrayTypes::make([''], $value);
			$result[$target->name] = [$name === 'preg_match_all' ? CallGraphArrayTypes::make(['@number:int'], [$row]) : $row];
		}
		return $result;
	}

	/**
	 * @param Expr $expr flags expression
	 * @return int|null literal/native flag mask, or unproved variable
	 */
	private static function flags(Expr $expr): ?int {
		if ($expr instanceof Node\Scalar\Int_) { return $expr->value; }
		if ($expr instanceof Expr\ConstFetch && defined($expr->name->toString()) && is_int(constant($expr->name->toString()))) { return constant($expr->name->toString()); }
		if ($expr instanceof Expr\BinaryOp\BitwiseOr) {
			$a = self::flags($expr->left); $b = self::flags($expr->right);
			return $a === null || $b === null ? null : $a | $b;
		}
		return null;
	}
}
