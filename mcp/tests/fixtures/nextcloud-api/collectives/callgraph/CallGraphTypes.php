<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * The possible types of an expression, from what the source states. A type is a FQCN, `FQCN[]` for an array of one
 * class, `class-string:FQCN` for a class name, `[]` for an empty array, '' for a value that is no object, and null
 * for a value of unknown type. A call has the union of the returns of every body it can land in (overrides
 * included); a body that states no class return gives the one type all its `return`s have, or null.
 */
final class CallGraphTypes {
	/** Array functions that give back elements of the arrays at these arguments, so the element type is kept. */
	private const KEEPING = ['array_values' => [0], 'array_filter' => [0], 'array_slice' => [0], 'array_unique' => [0], 'array_reverse' => [0],
		'array_combine' => [1], 'array_merge' => [0, 1, 2, 3]];

	public CallGraphLocals $locals;
	/** @var array<string, true> bodies whose return is being read, so a recursion gives no type */
	private array $reading = [];

	/**
	 * @param CallGraphIndex $index the parsed trees
	 * @param CallGraphDispatch $dispatch where calls land
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch) {
		$this->locals = new CallGraphLocals($index, $this);
	}

	/**
	 * @param string|null $class FQCN of the code, null in a function
	 * @param Expr $expr an expression
	 * @param array<string, list<?string>> $locals the local types known so far
	 * @return list<?string> its possible types
	 */
	public function typeOf(?string $class, Expr $expr, array $locals): array {
		return match (true) {
			$expr instanceof Expr\Variable => $expr->name === 'this' ? [$class] : (is_string($expr->name) ? ($locals[$expr->name] ?? [null]) : [null]),
			$expr instanceof Expr\New_ => $this->created($class, $expr, $locals),
			$expr instanceof Expr\Clone_ => $this->typeOf($class, $expr->expr, $locals),
			$expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp\Coalesce => $this->typeOf($class, $expr->expr, $locals),
			$expr instanceof Expr\Ternary => self::union([...$this->typeOf($class, $expr->if ?? $expr->cond, $locals), ...$this->typeOf($class, $expr->else, $locals)]),
			$expr instanceof Expr\BinaryOp\Coalesce => self::union([...$this->typeOf($class, $expr->left, $locals), ...$this->typeOf($class, $expr->right, $locals)]),
			$expr instanceof Expr\Array_ && $expr->items === [] => ['[]'],
			$expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch => $this->propertyType($class, $expr, $locals),
			$expr instanceof Expr\ArrayDimFetch => array_map(self::element(...), $this->typeOf($class, $expr->var, $locals)),
			$expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->callType($class, $expr, $locals),
			$expr instanceof Expr\StaticCall => $this->staticType($class, $expr),
			$expr instanceof Expr\FuncCall => $this->functionType($class, $expr, $locals),
			$expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && $expr->name->name === 'class'
				=> ['class-string:' . $this->index->name($class, $expr->class)],
			$expr instanceof Node\Scalar, $expr instanceof Expr\Array_, $expr instanceof Expr\ConstFetch, $expr instanceof Expr\ClassConstFetch,
			$expr instanceof Expr\BinaryOp, $expr instanceof Expr\BooleanNot, $expr instanceof Expr\Cast, $expr instanceof Expr\Isset_,
			$expr instanceof Expr\Empty_, $expr instanceof Expr\Instanceof_, $expr instanceof Expr\UnaryMinus, $expr instanceof Expr\Closure,
			$expr instanceof Expr\ArrowFunction, $expr instanceof Expr\Match_ => [''],
			default => [null],
		};
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\New_ $expr a `new`
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the class written, or every class name a variable can hold
	 */
	private function created(?string $class, Expr\New_ $expr, array $locals): array {
		if ($expr->class instanceof Node\Name) {
			return [$this->index->name($class, $expr->class)];
		}
		return $expr->class instanceof Expr ? array_map(static fn (?string $t): ?string => $t !== null && str_starts_with($t, 'class-string:')
			? substr($t, strlen('class-string:')) : null, $this->typeOf($class, $expr->class, $locals)) : [null];
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\PropertyFetch|Expr\NullsafePropertyFetch $expr a property read
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the property's stated type on each possible receiver
	 */
	private function propertyType(?string $class, Expr $expr, array $locals): array {
		if (!$expr->name instanceof Node\Identifier) {
			return [null];
		}
		return array_map(fn (?string $t): ?string => self::isObject($t) ? ($this->index->props((string)$t)[$expr->name->name] ?? null) : null,
			$this->typeOf($class, $expr->var, $locals));
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\MethodCall|Expr\NullsafeMethodCall $expr a method call
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the union of the returns of every body the call can land in
	 */
	private function callType(?string $class, Expr $expr, array $locals): array {
		if (!$expr->name instanceof Node\Identifier) {
			return [null];
		}
		$types = [];
		foreach ($this->typeOf($class, $expr->var, $locals) as $receiver) {
			array_push($types, ...(self::isObject($receiver) ? $this->returnTypes((string)$receiver, $expr->name->name) : [null]));
		}
		return self::union($types);
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\StaticCall $expr a static call
	 * @return list<?string> its returns; `Server::get(X::class)` is an X
	 */
	private function staticType(?string $class, Expr\StaticCall $expr): array {
		if (!$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
			return [null];
		}
		$target = $this->index->name($class, $expr->class);
		if ($target === 'OCP\\Server' && $expr->name->name === 'get') {
			return [self::literalClass($expr->args[0] ?? null)];
		}
		return $this->returnTypes($target, $expr->name->name);
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\FuncCall $expr a function call
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the elements an array function keeps, the return of a followed function, or no object
	 */
	private function functionType(?string $class, Expr\FuncCall $expr, array $locals): array {
		if (!$expr->name instanceof Node\Name) {
			return [null];
		}
		$name = strtolower($expr->name->toString());
		if (isset(self::KEEPING[$name])) {
			return self::union(array_merge(...array_map(fn (int $i): array => isset($expr->args[$i]) && $expr->args[$i] instanceof Node\Arg
				? $this->typeOf($class, $expr->args[$i]->value, $locals) : [null], self::KEEPING[$name])));
		}
		$units = $this->index->functions[$expr->name->toString()] ?? [];
		return $units === [] ? [''] : self::union(array_map($this->single(...), $units));
	}

	/**
	 * @param string $class FQCN of the receiver
	 * @param string $method method name
	 * @return list<?string> the return of every body or leaf the call can land in; null for one without a single type
	 */
	public function returnTypes(string $class, string $method): array {
		$ignored = [];
		$types = [];
		foreach ($this->dispatch->targets($class . '::' . $method, $ignored) as $target) {
			[$owner, $name] = explode('::', $target);
			$types[] = isset($this->index->classes[$owner]['methods'][$name]) ? $this->single($target) : null;
		}
		return $types === [] ? [null] : self::union($types);
	}

	/**
	 * @param string $key a unit key
	 * @return string|null its stated return, or the one type every `return` of its body has; null otherwise or on recursion
	 */
	private function single(string $key): ?string {
		$unit = $this->index->unit($key);
		if ($unit['returns'] !== null || isset($this->reading[$key]) || $unit['node']->stmts === null) {
			return $unit['returns'];
		}
		$this->reading[$key] = true;
		try {
			$locals = $this->locals->of($key);
			$types = [];
			foreach (self::returns($unit['node']->stmts) as $return) {
				array_push($types, ...($return->expr === null ? [''] : $this->typeOf($unit['class'], $return->expr, $locals)));
			}
			$types = self::union($types);
			return count($types) === 1 && $types[0] !== '' && $types[0] !== '[]' ? $types[0] : null;
		} finally {
			unset($this->reading[$key]);
		}
	}

	/**
	 * @param array<Node>|Node $nodes statements or a node
	 * @return list<Stmt\Return_> the returns among them, leaving out those of the closures written in them
	 */
	private static function returns(array|Node $nodes): array {
		$found = [];
		foreach (is_array($nodes) ? $nodes : [$nodes] as $node) {
			if ($node instanceof Stmt\Return_) {
				$found[] = $node;
			}
			if (!$node instanceof Node || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
				continue;
			}
			foreach ($node->getSubNodeNames() as $name) {
				$children = is_array($node->$name) ? $node->$name : [$node->$name];
				array_push($found, ...self::returns(array_filter($children, static fn ($n): bool => $n instanceof Node)));
			}
		}
		return $found;
	}

	/**
	 * @param string|null $type a possible type
	 * @return bool whether it is an object of a known class
	 */
	public static function isObject(?string $type): bool {
		return $type !== null && $type !== '' && !str_ends_with($type, '[]') && !str_starts_with($type, 'class-string:');
	}

	/**
	 * @param string|null $type a possible type
	 * @return string|null the type of an element when it is an array of a class; null otherwise
	 */
	public static function element(?string $type): ?string {
		return $type !== null && $type !== '[]' && str_ends_with($type, '[]') ? substr($type, 0, -2) : null;
	}

	/**
	 * @param list<?string> $types possible types
	 * @return list<?string> without repeats; an empty array (`[]`) adds nothing to an array of a class
	 */
	public static function union(array $types): array {
		$types = array_values(array_unique($types, SORT_REGULAR));
		$arrays = array_filter($types, static fn (?string $t): bool => self::element($t) !== null);
		return $arrays === [] ? $types : array_values(array_diff($types, ['[]']));
	}

	/**
	 * @param Node|null $arg an argument
	 * @return string|null the class it names when it is written in place as `X::class`
	 */
	public static function literalClass(?Node $arg): ?string {
		return $arg instanceof Node\Arg && $arg->value instanceof Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
			&& $arg->value->name instanceof Node\Identifier && $arg->value->name->name === 'class' ? $arg->value->class->toString() : null;
	}
}
