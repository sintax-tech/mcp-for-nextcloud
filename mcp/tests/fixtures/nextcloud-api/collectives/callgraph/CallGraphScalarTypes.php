<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Numeric results and enum case objects come from explicit declarations, literal values and PHP operator semantics. */
final class CallGraphScalarTypes {
	/**
	 * @param string $name native or docblock scalar type
	 * @return string|null scalar abstraction, or null when no scalar is declared
	 */
	public static function declared(string $name): ?string {
		return match (strtolower($name)) {
			'int', 'integer' => '@number:int', 'float', 'double' => '@number:float',
			'string', 'bool', 'boolean', 'scalar', 'null', 'void', 'never', 'false', 'true', 'resource', 'array-key' => '',
			default => null,
		};
	}

	/**
	 * @param Expr $node arithmetic expression
	 * @param callable(Expr):list<?string> $typeOf operand evidence
	 * @return list<?string> numeric result only for proved numeric operands
	 */
	public static function arithmetic(Expr $node, callable $typeOf): array {
		$operands = $node instanceof Expr\BinaryOp ? [$node->left, $node->right]
			: [($node instanceof Expr\PreInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) ? $node->var : $node->expr];
		$types = array_merge(...array_map($typeOf, $operands));
		if ($node instanceof Expr\BinaryOp\Plus && $types !== [] && array_filter($types, static fn (?string $t): bool => CallGraphArrayTypes::read($t) === null) === []) {
			return CallGraphTypes::union($types);
		}
		if (in_array('@bottom', $types, true) && !in_array(null, $types, true)) { return ['@bottom']; }
		if ($types === [] || array_filter($types, static fn (?string $t): bool => $t === null || $t === '@bottom' || CallGraphTypes::isObject($t) || CallGraphArrayTypes::read($t) !== null) !== []) {
			return [null];
		}
		if (array_filter($types, static fn (?string $t): bool => !in_array($t, ['@number:int', '@number:float'], true)) !== []) {
			return ['']; // Arithmetic/increment on proved primitive values either yields a primitive or throws; it cannot dispatch user object hooks.
		}
		return in_array('@number:float', $types, true) || $node instanceof Expr\BinaryOp\Div || $node instanceof Expr\BinaryOp\Pow
			? ['@number:int', '@number:float'] : ['@number:int'];
	}

	/**
	 * @param Expr\ConstFetch $expr constant expression
	 * @return list<?string> literal/builtin constants only; unresolved user constants remain unknown
	 */
	public static function globalConstant(Expr\ConstFetch $expr): array {
		$name = $expr->name->toString();
		if (in_array(strtolower($name), ['true', 'false', 'null'], true)) { return ['']; }
		$groups = get_defined_constants(true);
		unset($groups['user']);
		$native = array_merge(...array_values($groups));
		if (!array_key_exists($name, $native)) { return [null]; }
		$value = $native[$name];
		return is_int($value) ? ['@number:int'] : (is_float($value) ? ['@number:float'] : (is_scalar($value) || $value === null ? [''] : [null]));
	}

	/**
	 * @param CallGraphIndex $index declarations
	 * @param string|null $class lexical class
	 * @param Expr\ClassConstFetch $expr class constant or enum case
	 * @param callable(Expr):list<?string> $typeOf reads a declared constant value
	 * @return list<?string> the enum object itself or the actual constant's type
	 */
	public static function constant(CallGraphIndex $index, ?string $class, Expr\ClassConstFetch $expr, callable $typeOf): array {
		if (!$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
			return [null];
		}
		$owner = $index->name($class, $expr->class);
		$declaration = $index->classes[$owner]['node'] ?? null;
		if ($declaration instanceof Stmt\Enum_) {
			foreach (array_filter($declaration->stmts, static fn (Node $node): bool => $node instanceof Stmt\EnumCase) as $case) {
				if ($case->name->name === $expr->name->name) {
					return [$owner];
				}
			}
		}
		if (class_exists($owner, false) && ($reflection = new ReflectionClass($owner))->isInternal() && $reflection->hasConstant($expr->name->name)) {
			$value = $reflection->getConstant($expr->name->name);
			return is_int($value) ? ['@number:int'] : (is_float($value) ? ['@number:float'] : (is_scalar($value) || $value === null ? [''] : [null]));
		}
		$value = $index->constant($owner, $expr->name->name);
		return $value === null ? [null] : $typeOf($value);
	}
}
