<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** String offsets always coerce; native property strings coerce only in weak files, including patterns and loops. */
final class CallGraphWriteCoercions {
	/**
	 * @param CallGraphIndex $index declarations
	 * @param CallGraphDispatch $dispatch concrete receivers
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch) {
	}

	/**
	 * @param Node $node potential write
	 * @param string|null $scope lexical class
	 * @param callable(Expr):list<?string> $typeOf source evidence
	 * @param bool $weak whether native scalar property coercion applies
	 * @return list<list<?string>> values requiring string conversion
	 */
	public function values(Node $node, ?string $scope, callable $typeOf, bool $weak): array {
		if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp) {
			return $this->target($node->var, $typeOf($node->expr), $scope, $typeOf, $weak);
		}
		if ($node instanceof Stmt\Foreach_) {
			$keys = $values = [];
			foreach ($typeOf($node->expr) as $type) {
				$array = CallGraphArrayTypes::read($type);
				array_push($keys, ...($array['keys'] ?? [null]));
				array_push($values, ...($array['values'] ?? [null]));
			}
			return [...$this->target($node->valueVar, $values, $scope, $typeOf, $weak),
				...($node->keyVar === null ? [] : $this->target($node->keyVar, $keys, $scope, $typeOf, $weak))];
		}
		return [];
	}

	/**
	 * @param Expr $target assignment destination
	 * @param list<?string> $types values reaching that destination
	 * @param string|null $scope lexical class
	 * @param callable(Expr):list<?string> $typeOf receiver evidence
	 * @param bool $weak native property coercion mode
	 * @return list<list<?string>> values requiring string conversion
	 */
	private function target(Expr $target, array $types, ?string $scope, callable $typeOf, bool $weak): array {
		if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
			$result = [];
			foreach ($target->items as $i => $item) {
				if ($item === null) { continue; }
				$key = $item->key === null ? $i : ($item->key instanceof Node\Scalar ? $item->key->value : null);
				$values = array_merge(...array_map(static fn (?string $type): array => CallGraphArrayTypes::values($type, is_int($key) || is_string($key) ? $key : null), $types));
				array_push($result, ...$this->target($item->value, $values, $scope, $typeOf, $weak));
			}
			return $result;
		}
		if ($target instanceof Expr\ArrayDimFetch && in_array('', $typeOf($target->var), true)) { return [$types]; }
		if (!$weak) { return []; }
		if (!$target instanceof Expr\PropertyFetch && !$target instanceof Expr\StaticPropertyFetch) { return []; }
		$receivers = $target instanceof Expr\StaticPropertyFetch ? ($target->class instanceof Node\Name ? [$this->index->name($scope, $target->class)] : [null]) : $typeOf($target->var);
		foreach ($receivers as $class) {
			foreach (CallGraphTypes::isObject($class) ? $this->dispatch->instances($class) : [] as $candidate) {
				foreach ([$candidate, ...$this->index->ancestors($candidate)] as $ancestor) {
					$node = $this->index->classes[$ancestor]['node'] ?? null;
					foreach ($node?->getProperties() ?? [] as $property) {
						foreach ($property->props as $prop) {
							if ((!$target->name instanceof Node\Identifier || $target->name->name === $prop->name->name) && self::string($property->type)) { return [$types]; }
						}
					}
					foreach ($node?->getMethods() ?? [] as $method) {
						foreach ($method->params as $param) {
							if ($param->flags !== 0 && (!$target->name instanceof Node\Identifier || $target->name->name === $param->var->name) && self::string($param->type)) { return [$types]; }
						}
					}
				}
			}
		}
		return [];
	}

	/**
	 * @param Expr $target direct destination or nested destructuring pattern
	 * @return list<Expr> concrete property/index/variable write destinations
	 */
	public static function destinations(Expr $target): array {
		if (!$target instanceof Expr\List_ && !$target instanceof Expr\Array_) { return [$target]; }
		$result = [];
		foreach ($target->items as $item) {
			if ($item !== null) { array_push($result, ...self::destinations($item->value)); }
		}
		return $result;
	}

	/**
	 * @param Node|null $type native declaration
	 * @return bool whether it accepts/coerces strings
	 */
	private static function string(?Node $type): bool {
		return $type instanceof Node\UnionType && array_filter($type->types, self::string(...)) !== []
			|| $type instanceof Node\NullableType && self::string($type->type)
			|| $type instanceof Node\Identifier && $type->toLowerString() === 'string';
	}
}
