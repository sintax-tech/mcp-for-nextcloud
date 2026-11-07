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
 * class (`scalar[]` for proven scalar elements), `class-string:FQCN` for a class name, `[]` for an empty array, '' for a value that is no object, and null
 * for a value of unknown type. A call has the union of the returns of every body it can land in (overrides
 * included); a body that states no class return gives the one type all its `return`s have, or null.
 */
final class CallGraphTypes {
	public CallGraphLocals $locals;
	public CallGraphBindings $bindings;
	/** @var array<string, true> bodies whose return is being read, so a recursion gives no type */
	public CallGraphReturns $returns;
	public CallGraphPropertyFacts $properties;
	private CallGraphFunctionTypes $functions;
	private CallGraphFetchTypes $fetch;

	/**
	 * @param CallGraphIndex $index the parsed trees
	 * @param CallGraphDispatch $dispatch where calls land
	 * @param array<string,array<string,string>> $fetchReturns source-proved fetch returns
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch, array $fetchReturns = []) {
		$this->properties = new CallGraphPropertyFacts($index, $this);
		$this->returns = new CallGraphReturns($index, $this);
		$this->fetch = new CallGraphFetchTypes($fetchReturns);
		$this->bindings = new CallGraphBindings();
		$this->functions = new CallGraphFunctionTypes($index, $this);
		$this->locals = new CallGraphLocals($index, $this);
	}

	/**
	 * @param string|null $class FQCN of the code, null in a function
	 * @param Expr $expr an expression
	 * @param array<string, list<?string>> $locals the local types known so far
	 * @return list<?string> its possible types
	 */
	public function typeOf(?string $class, Expr $expr, array $locals): array {
		if (!CallGraphSubset::allows($expr)) {
			return [null];
		}
		$typeOf = fn (Expr $e): array => $this->typeOf($class, $e, $locals);
		return self::union(match (true) {
			$expr instanceof Node\Scalar\Int_ => ['@number:int'], $expr instanceof Node\Scalar\Float_ => ['@number:float'],
			$expr instanceof Expr\PreInc, $expr instanceof Expr\PreDec, $expr instanceof Expr\PostInc, $expr instanceof Expr\PostDec,
			$expr instanceof Expr\UnaryMinus, $expr instanceof Expr\UnaryPlus,
			$expr instanceof Expr\BinaryOp\Plus, $expr instanceof Expr\BinaryOp\Minus, $expr instanceof Expr\BinaryOp\Mul,
			$expr instanceof Expr\BinaryOp\Div, $expr instanceof Expr\BinaryOp\Mod, $expr instanceof Expr\BinaryOp\Pow
				=> CallGraphScalarTypes::arithmetic($expr, $typeOf),
			$expr instanceof Expr\Cast\Int_ => ['@number:int'], $expr instanceof Expr\Cast\Double => ['@number:float'],
			$expr instanceof Expr\Variable => $expr->name === 'this' ? [$class] : (is_string($expr->name) ? ($locals[$expr->name] ?? [null]) : [null]),
			$expr instanceof Expr\New_ => $this->created($class, $expr, $locals),
			$expr instanceof Expr\Include_ => ['scalar[]'],
			$expr instanceof Expr\Clone_ => $this->typeOf($class, $expr->expr, $locals),
			$expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp\Coalesce => $this->typeOf($class, $expr->expr, $locals),
			$expr instanceof Expr\Match_ => self::union(array_merge(...array_map(fn (Node\MatchArm $arm): array => $arm->body instanceof Expr\Throw_ ? [] : $typeOf($arm->body), $expr->arms))),
			$expr instanceof Expr\Ternary => self::union([...$this->typeOf($class, $expr->if ?? $expr->cond, $locals), ...$this->typeOf($class, $expr->else, $locals)]),
			$expr instanceof Expr\BinaryOp\Coalesce => self::union([...$this->typeOf($class, $expr->left, $locals), ...$this->typeOf($class, $expr->right, $locals)]),
			$expr instanceof Expr\Array_ => $this->arrayType($class, $expr, $locals),
			$expr instanceof Expr\StaticPropertyFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\VarLikeIdentifier
				=> [$this->properties->of($this->index->name($class, $expr->class), $expr->name->name)],
			$expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch => $this->propertyType($class, $expr, $locals),
			$expr instanceof Expr\ArrayDimFetch => $this->offsetType($class, $expr, $locals),
			$expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->callType($class, $expr, $locals),
			$expr instanceof Expr\StaticCall => $this->staticType($class, $expr),
			$expr instanceof Expr\FuncCall => $this->functions->of($class, $expr, $locals),
			$expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && $expr->name->name === 'class'
				=> ['class-string:' . $this->index->name($class, $expr->class)],
			$expr instanceof Expr\ClassConstFetch => CallGraphScalarTypes::constant($this->index, $class, $expr, $typeOf),
			$expr instanceof Expr\ConstFetch => CallGraphScalarTypes::globalConstant($expr),
			$expr instanceof Node\Scalar,
			$expr instanceof Expr\BinaryOp, $expr instanceof Expr\BooleanNot, $expr instanceof Expr\Cast, $expr instanceof Expr\Isset_,
			$expr instanceof Expr\Empty_, $expr instanceof Expr\Instanceof_, $expr instanceof Expr\UnaryMinus, $expr instanceof Expr\Closure,
			$expr instanceof Expr\ArrowFunction => [''],
			default => [null],
		});
	}

	/**
	 * @param string|null $class containing class
	 * @param Expr\ArrayDimFetch $expr array or ArrayAccess read
	 * @param array<string,list<?string>> $locals expression evidence
	 * @return list<?string> array element or the union of actual offsetGet returns
	 */
	private function offsetType(?string $class, Expr\ArrayDimFetch $expr, array $locals): array {
		$types = [];
		foreach ($this->typeOf($class, $expr->var, $locals) as $receiver) {
			array_push($types, ...(self::isObject($receiver) ? $this->returnTypes($receiver, 'offsetGet') : CallGraphArrayTypes::values($receiver, $expr->dim instanceof Node\Scalar\String_ || $expr->dim instanceof Node\Scalar\Int_ ? $expr->dim->value : null)));
		}
		return self::union($types);
	}

	/** @return CallGraphProtocols protocol inference shares the graph's declarations and return evidence */
	public function protocols(): CallGraphProtocols {
		return new CallGraphProtocols($this->index, $this->dispatch, $this, new CallGraphArguments($this->index, $this->dispatch, $this));
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\New_ $expr a `new`
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the class written, or every class name a variable can hold
	 */
	private function created(?string $class, Expr\New_ $expr, array $locals): array {
		if ($expr->class instanceof Node\Name) {
			return in_array(strtolower($expr->class->toString()), ['self', 'static'], true)
				? $this->dispatch->instances((string)$class) : [$this->index->name($class, $expr->class)];
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
		return array_map(fn (?string $t): ?string => in_array($t, ['json-value', 'json-object-value'], true) ? $t : (self::isObject($t) ? $this->properties->of((string)$t, $expr->name->name) : null),
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
			array_push($types, ...(self::isObject($receiver) ? $this->returnTypes((string)$receiver, $expr->name->name, false, $expr) : [null]));
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
		if ($target === 'OCP\\Server' && $expr->name->toLowerString() === 'get') {
			return [self::literalClass($expr->args[0] ?? null)];
		}
		return $this->returnTypes($target, $expr->name->name, true);
	}

	/**
	 * @param string|null $class the containing class
	 * @param Expr\Array_ $expr a literal array
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> proven array element types, preserving an unknown or an object element
	 */
	private function arrayType(?string $class, Expr\Array_ $expr, array $locals): array {
		return CallGraphArrayExpressions::literal($class, $expr, $locals, $this);
	}

	/**
	 * @param string $class FQCN of the receiver
	 * @param string $method method name
	 * @param Expr|null $call actual method arguments for a pinned fetch model
	 * @param bool $static whether a missing method must use __callStatic
	 * @return list<?string> the return of every body or leaf the call can land in; null for one without a single type
	 */
	public function returnTypes(string $class, string $method, bool $static = false, ?Expr $call = null): array {
		$ignored = [];
		$types = [];
		foreach ($this->dispatch->targets(($static ? 'static ' : '') . $class . '::' . $method, $ignored) as $target) {
			[$owner, $name] = explode('::', $target);
			[$fetch, $fetched] = $call === null ? [false, null] : $this->fetch->result($target, $call);
			if ($fetch) {
				$types[] = $fetched;
				continue;
			}
			if (strtolower($name) === '__call' && strtolower($method) !== '__call') {
				foreach ($this->dispatch->instances($class) as $concrete) {
					$types[] = CallGraphMagicContracts::result($this->index, $concrete, $method);
				}
			} else {
				foreach ($this->dispatch->instances($class) as $concrete) {
					[$generic, $substitution] = CallGraphTemplates::result($this->index, $concrete, $owner, $name);
					$types[] = $generic ? $substitution : (isset($this->index->classes[$owner]['methods'][strtolower($name)]) ? $this->unitReturn($target) : null);
				}
			}
		}
		return $types === [] ? [null] : self::union($types);
	}

	/**
	 * @param string $key a unit key
	 * @return string|null its stated return, or the one type every `return` of its body has; null otherwise or on recursion
	 */
	public function unitReturn(string $key): ?string {
		return $this->returns->of($key);
	}

	/**
	 * @param array<Node>|Node $nodes statements or a node
	 * @return list<Stmt\Return_> the returns among them, leaving out those of the closures written in them
	 */
	public static function returns(array|Node $nodes): array {
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
		return $type !== null && !in_array($type, ['', 'json-value', 'json-object-value'], true) && !str_ends_with($type, '[]') && !str_starts_with($type, 'class-string:') && !str_starts_with($type, '@');
	}

	/**
	 * @param string|null $type a possible type
	 * @return string|null the type of an element when it is an array of a class; null otherwise
	 */
	public static function element(?string $type): ?string {
		return CallGraphArrayTypes::pack(CallGraphArrayTypes::values($type));
	}

	/**
	 * @param list<?string> $types possible types
	 * @return list<?string> without repeats; an empty array (`[]`) adds nothing to an array of a class
	 */
	public static function union(array $types): array {
		return CallGraphArrayTypes::merge($types);
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
