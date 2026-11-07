<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Literal names flow through actual getter delegation; a get-prefix alone is never a return-type proof. */
final class CallGraphMagicFields {
	/**
	 * @param CallGraphIndex $index pinned declarations
	 * @param string $class concrete receiver, retained through inherited bodies
	 * @param string $method body to interpret
	 * @param list<array> $args literal or type evidence supplied to its parameters
	 * @param int $depth recursion bound
	 * @return array|null proved literal/type value, otherwise unknown
	 */
	public static function call(CallGraphIndex $index, string $class, string $method, array $args, int $depth = 0): ?array {
		$owner = (new CallGraphDispatch($index))->owner($class, $method);
		if ($owner === null || $depth > 8) {
			return null;
		}
		$body = $index->classes[$owner]['methods'][strtolower($method)]['node'];
		$locals = [];
		foreach ($body->params as $i => $param) {
			$locals[$param->var->name] = $args[$i] ?? null;
		}
		$eval = static function (Expr $expr) use ($index, $class, &$locals, $depth, &$eval): ?array {
			if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_) {
				return ['literal' => $expr->value];
			}
			if ($expr instanceof Expr\Variable && is_string($expr->name)) {
				return $locals[$expr->name] ?? null;
			}
			if ($expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this') {
				$name = $expr->name instanceof Node\Identifier ? $expr->name->name : ($eval($expr->name)['literal'] ?? null);
				foreach ([$class, ...$index->ancestors($class)] as $ancestor) {
					$props = $index->classes[$ancestor]['declaredProps'] ?? [];
					if (is_string($name) && array_key_exists($name, $props)) {
						return $props[$name] === null ? null : ['type' => $props[$name]];
					}
				}
				return null;
			}
			if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
				if ($index->functionName($expr->name) === 'property_exists' && ($expr->args[0]->value ?? null) instanceof Expr\Variable
					&& $expr->args[0]->value->name === 'this') {
					$name = isset($expr->args[1]) ? ($eval($expr->args[1]->value)['literal'] ?? null) : null;
					return is_string($name) ? ['literal' => array_key_exists($name, $index->props($class))] : null;
				}
				$args = array_map(static fn (Node\Arg $a): mixed => $eval($a->value)['literal'] ?? null, $expr->args);
				if (in_array(null, $args, true)) {
					return null;
				}
				$value = match ($index->functionName($expr->name)) {
					'lcfirst' => lcfirst($args[0]), 'substr' => substr(...$args),
					'str_starts_with' => str_starts_with(...$args), default => null,
				};
				return $value === null ? null : ['literal' => $value];
			}
			if ($expr instanceof Expr\MethodCall && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
				return self::call($index, $class, $expr->name->name, array_map(static fn (Node\Arg $a): array => $eval($a->value) ?? [], $expr->args), $depth + 1);
			}
			return null;
		};
		$todo = $body->stmts ?? [];
		while ($todo !== []) {
			$node = array_shift($todo);
			if ($node instanceof Stmt\Return_) {
				return $node->expr === null ? ['type' => ''] : $eval($node->expr);
			}
			if ($node instanceof Stmt\Expression && $node->expr instanceof Expr\Assign && $node->expr->var instanceof Expr\Variable) {
				$locals[$node->expr->var->name] = $eval($node->expr->expr);
			} elseif ($node instanceof Stmt\If_) {
				$branch = null;
				foreach ([$node, ...$node->elseifs] as $condition) {
					$value = $eval($condition->cond)['literal'] ?? null;
					if (!is_bool($value)) {
						return null;
					}
					if ($value) {
						$branch = $condition->stmts;
						break;
					}
				}
				$todo = [...($branch ?? $node->else?->stmts ?? []), ...$todo];
			} else {
				return null;
			}
		}
		return null;
	}
}
