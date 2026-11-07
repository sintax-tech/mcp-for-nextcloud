<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * The types of the variables of a body, flow-insensitive: each variable has the union of every value it is given
 * (assignments, `$list[] =`, foreach items and keys, caught exceptions, closure parameters, inline `@var`). Every
 * variable starts with no type; assignments that read other variables are settled by repeating until nothing changes,
 * and a variable still without a type in the end is unknown.
 */
final class CallGraphLocals {
	/** Array functions whose closure receives elements: callback argument, array argument, how many parameters get one. */
	public const ITERATING = ['array_map' => [0, 1, 1], 'array_filter' => [1, 0, 1], 'usort' => [1, 0, 2], 'uasort' => [1, 0, 2]];

	/**
	 * @param CallGraphIndex $index the parsed trees
	 * @param CallGraphTypes $types expression types
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphTypes $types) {
	}

	/**
	 * @param string $key a unit key
	 * @return array<string, list<?string>> variable name to its possible types
	 */
	public function of(string $key): array {
		$unit = $this->index->unit($key);
		$finder = new NodeFinder();
		$all = $finder->find($unit['node']->stmts ?? [], static fn (Node $n): bool => true);
		$start = [];
		foreach ($finder->findInstanceOf($unit['node']->stmts ?? [], Expr\Variable::class) as $var) {
			if (is_string($var->name) && $var->name !== 'this') {
				$start[$var->name] = [];
			}
		}
		$start = array_merge($start, array_map(static fn (string $type): array => [$type], $unit['params']));
		$given = $this->types->bindings->of($key);
		foreach ($given as $name => $values) {
			$stated = $unit['params'][$name] ?? null;
			$array = CallGraphArrayTypes::read($stated);
			if ($stated === null || ($array !== null && in_array(null, $array['values'], true))) {
				$start[$name] = $values;
			}
		}
		$locals = $start;
		for ($round = 0; $round < 8; $round++) {
			$next = $start;
			foreach ($all as $n) {
				$this->give($unit['class'], $n, $locals, $next);
			}
			$next = array_map(CallGraphTypes::union(...), $next);
			if ($next === $locals) {
				break;
			}
			$locals = $next;
		}
		return array_map(static fn (array $types): array => $types === [] ? [null] : $types, $locals);
	}

	/**
	 * Adds what one node gives to the variables.
	 * @param string|null $class FQCN of the code
	 * @param Node $n a node of the body
	 * @param array<string, list<?string>> $locals the types of the previous round
	 * @param array<string, list<?string>> $next the types of this round
	 * @return void
	 */
	private function give(?string $class, Node $n, array $locals, array &$next): void {
		$add = static function (string $name, array $types) use (&$next): void {
			$next[$name] = [...($next[$name] ?? []), ...$types];
		};
		if ($n instanceof Stmt\Expression && $n->expr instanceof Expr\Assign && $n->expr->var instanceof Expr\Variable && is_string($n->expr->var->name)
			&& ($stated = $this->inlineVar($class, $n, $n->expr->var->name)) !== null) {
			$add($n->expr->var->name, [$stated]);
			$n->expr->setAttribute('stated', true);
		} elseif ($n instanceof Expr\Assign && $n->var instanceof Expr\ArrayDimFetch && $n->var->var instanceof Expr\Variable && is_string($n->var->var->name)) {
			// `$list[] = $x` makes $list an array of the type of $x.
			$add($n->var->var->name, array_map(static fn (?string $t): ?string => $t === null ? null : ($t === '' ? 'scalar[]' : $t . '[]'),
				$this->types->typeOf($class, $n->expr, $locals)));
		} elseif ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name && isset(self::ITERATING[strtolower($this->index->functionName($n->name))])) {
			[$callback, $array, $count] = self::ITERATING[strtolower($this->index->functionName($n->name))];
			$fn = $n->args[$callback]->value ?? null;
			if (($fn instanceof Expr\Closure || $fn instanceof Expr\ArrowFunction) && isset($n->args[$array]) && count($n->args) === 2) {
				$fn->setAttribute('elements', [array_map(CallGraphTypes::element(...), $this->types->typeOf($class, $n->args[$array]->value, $locals)), $count]);
			}
		} elseif (($n instanceof Expr\Assign || $n instanceof Expr\AssignOp\Coalesce) && !$n->hasAttribute('stated')) {
			foreach (self::targets($n->var) as $name) {
				$add($name, $n->var instanceof Expr\Variable ? $this->types->typeOf($class, $n->expr, $locals)
					: array_map(CallGraphTypes::element(...), $this->types->typeOf($class, $n->expr, $locals)));
			}
		} elseif ($n instanceof Stmt\Foreach_) {
			[$keys, $values] = $this->iterated($class, $n->expr, $locals);
			foreach (self::targets($n->valueVar) as $name) {
				$add($name, $n->valueVar instanceof Expr\Variable ? $values : [null]);
			}
			foreach ($n->keyVar === null ? [] : self::targets($n->keyVar) as $name) {
				$add($name, $keys);
			}
		} elseif ($n instanceof Stmt\Catch_ && $n->var !== null && is_string($n->var->name)) {
			$add($n->var->name, array_map(fn (Node\Name $type): string => $this->index->name($class, $type), $n->types));
		} elseif ($n instanceof Expr\Closure || $n instanceof Expr\ArrowFunction) {
			[$elements, $count] = $n->getAttribute('elements', [[null], 0]);
			foreach ($n->params as $i => $param) {
				$add((string)$param->var->name, $param->type instanceof Node\Name ? [$this->index->name($class, $param->type)] : ($i < $count ? $elements : [null]));
			}
		} elseif ($n instanceof Stmt\Static_) {
			foreach ($n->vars as $var) {
				$add($var->var->name, $var->default === null ? [null] : $this->types->typeOf($class, $var->default, $locals));
			}
		} elseif ($n instanceof Stmt\Global_) {
			foreach ((new NodeFinder())->findInstanceOf([$n], Expr\Variable::class) as $var) {
				if (is_string($var->name)) {
					$add($var->name, [null]);
				}
			}
		}
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr $expr what a foreach goes over
	 * @param array<string, list<?string>> $locals local types
	 * @return array{0:list<?string>, 1:list<?string>} the types of its keys and of its values
	 */
	private function iterated(?string $class, Expr $expr, array $locals): array {
		// A class constant written as an array: its keys and values are read from the source.
		if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
			&& ($value = $this->index->constant($this->index->name($class, $expr->class), $expr->name->name)) instanceof Expr\Array_) {
			$keys = [];
			$values = [];
			foreach ($value->items as $item) {
				$keys = [...$keys, ...($item->key === null ? [''] : $this->types->typeOf($class, $item->key, $locals))];
				$values = [...$values, ...$this->types->typeOf($class, $item->value, $locals)];
			}
			return [$keys === [] ? [''] : $keys, $values === [] ? [null] : $values];
		}
		$keys = [];
		$values = [];
		foreach ($this->types->typeOf($class, $expr, $locals) as $type) {
			[$k, $v] = CallGraphTypes::isObject($type) ? $this->types->protocols()->elements($type) : [CallGraphArrayTypes::read($type)['keys'] ?? [''], CallGraphArrayTypes::values($type)];
			array_push($keys, ...$k);
			array_push($values, ...$v);
		}
		return [CallGraphTypes::union($keys), CallGraphTypes::union($values)];
	}

	/**
	 * @param Expr $var the left side of an assignment
	 * @return list<string> the plain variables it writes
	 */
	private static function targets(Expr $var): array {
		if ($var instanceof Expr\Variable) {
			return is_string($var->name) ? [$var->name] : [];
		}
		if ($var instanceof Expr\List_ || $var instanceof Expr\Array_) {
			return array_merge([], ...array_map(static fn ($item): array => $item === null ? [] : self::targets($item->value), $var->items));
		}
		return [];
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Stmt\Expression $statement an assignment statement
	 * @param string $name the variable it assigns
	 * @return string|null the type an inline `@var` docblock states for it
	 */
	private function inlineVar(?string $class, Stmt\Expression $statement, string $name): ?string {
		if (!preg_match('/@var\s+(\S+)\s+\$' . $name . '\b/', $statement->getDocComment()?->getText() ?? '', $m)) {
			return null;
		}
		$imports = $class === null ? [] : $this->index->imports($class);
		return CallGraphNames::docType($imports, $class === null ? '' : substr($class, 0, (int)strrpos($class, '\\') + 1), $class, $m[1]);
	}
}
