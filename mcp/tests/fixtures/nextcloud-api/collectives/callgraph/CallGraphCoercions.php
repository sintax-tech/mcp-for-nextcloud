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
use PhpParser\ParserFactory;

/** PHP weak string declarations can dispatch __toString at arguments, returns and property writes. */
final class CallGraphCoercions {
	/** @var array<string,bool> immutable source files by path */
	private array $weakFiles = [];

	/**
	 * @param CallGraphIndex $index declarations
	 * @param CallGraphDispatch $dispatch targets
	 * @param CallGraphImplicitEdges $edges shared magic dispatcher
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch, private CallGraphImplicitEdges $edges) {
	}

	/**
	 * @param Node $node reached body node
	 * @param string $caller canonical unit
	 * @param callable(Expr):list<?string> $typeOf expression types
	 * @param callable(Node,string):void $miss source-positioned rejection
	 * @return list<string> required conversion bodies
	 */
	public function calls(Node $node, string $caller, callable $typeOf, callable $miss): array {
		$unit = $this->index->unit($caller);
		$edges = [];
		foreach ($this->callbackValues($node, $typeOf) as $value) {
			array_push($edges, ...$this->edges->of($value, ['__toString'], $node, $caller, $miss, [], false));
		}
		if (!$this->weak($unit['path'])) {
			return $edges;
		}
		$values = [];
		if ($node instanceof Expr\ArrowFunction && self::string($node->returnType)) { $values[] = $node->expr; }
		if ($node instanceof Stmt\Return_ && $node->expr !== null) {
			$parent = $node->getAttribute('parent');
			while ($parent instanceof Node && !$parent instanceof Node\FunctionLike) {
				$parent = $parent->getAttribute('parent');
			}
			if ($parent instanceof Node\FunctionLike && self::string($parent->getReturnType())) {
				$values[] = $node->expr;
			}
		}
		foreach ((new CallGraphWriteCoercions($this->index, $this->dispatch))->values($node, $unit['class'], $typeOf) as $types) {
			array_push($edges, ...$this->edges->of($types, ['__toString'], $node, $caller, $miss, [], false));
		}
		$calls = [];
		if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
			foreach ($typeOf($node->var) as $class) {
				if (CallGraphTypes::isObject($class)) {
					$calls[] = $class . '::' . $node->name->name;
				}
			}
		} elseif ($node instanceof Expr\New_) {
			foreach ($typeOf($node) as $class) {
				if (CallGraphTypes::isObject($class)) {
					$calls[] = $class . '::__construct';
				}
			}
		} elseif ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
			$calls[] = 'static ' . $this->index->name($unit['class'], $node->class) . '::' . $node->name->name;
		} elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
			$calls[] = 'function ' . $this->index->functionName($node->name);
		} elseif ($node instanceof Expr\FuncCall && $node->name instanceof Expr) {
			foreach ($typeOf($node->name) as $class) {
				if (CallGraphTypes::isObject($class)) { $calls[] = $class . '::__invoke'; }
			}
		}
		foreach ($calls as $call) {
			$ignored = [];
			foreach ($this->dispatch->targets($call, $ignored) as $target) {
				$name = strtolower(explode('::', $target)[1] ?? '');
				if (in_array($name, ['__call', '__callstatic'], true) && $node->name instanceof Node\Identifier && $node->name->toLowerString() !== $name) {
					continue; // PHP supplies the literal method name and a packed array, not the original argument positions.
				}
				$parameters = $this->parameters($target);
				foreach ($parameters as $i => $string) {
					if ($string && ($node->args[$i] ?? null) instanceof Node\Arg) {
						$values[] = $node->args[$i]->value;
					}
				}
			}
		}
		foreach ($values as $value) {
			array_push($edges, ...$this->edges->of($typeOf($value), ['__toString'], $node, $caller, $miss, [], false));
		}
		return array_values(array_unique($edges));
	}

	/**
	 * @param Node $node internal callback invocation
	 * @param callable(Expr):list<?string> $typeOf source values
	 * @return list<list<?string>> values weakly coerced by explicitly resolved inline callback string parameters
	 */
	private function callbackValues(Node $node, callable $typeOf): array {
		if (!$node instanceof Expr\FuncCall || !$node->name instanceof Node\Name) { return []; }
		$name = strtolower($this->index->functionName($node->name));
		$model = CallGraphFunctions::model($name);
		if (($model['effect'] ?? null) !== 'callback') { return []; }
		$values = [];
		foreach ($model['arguments'] as $position) {
			$callback = $node->args[$position]->value ?? null;
			if (!$callback instanceof Expr\Closure && !$callback instanceof Expr\ArrowFunction) { continue; }
			foreach ($callback->params as $i => $param) {
				if (!self::string($param->type)) { continue; }
				if ($name === 'ob_start') { continue; } // PHP supplies output text and integer phase flags.
				if ($name === 'call_user_func') {
					$values[] = isset($node->args[$i + 1]) ? $typeOf($node->args[$i + 1]->value) : [null];
				} elseif ($name === 'call_user_func_array') {
					$values[] = array_merge(...array_map(static fn (?string $t): array => CallGraphArrayTypes::values($t, $i), isset($node->args[1]) ? $typeOf($node->args[1]->value) : [null]));
				} else {
					$argument = $name === 'array_map' ? ($node->args[$i + 1]->value ?? null) : ($node->args[0]->value ?? null);
					$values[] = $argument === null ? [null] : array_merge(...array_map(static fn (?string $t): array => $name === 'uksort'
						? (CallGraphArrayTypes::read($t)['keys'] ?? [null]) : CallGraphArrayTypes::values($t), $typeOf($argument)));
				}
			}
		}
		return $values;
	}

	/**
	 * @param string $target resolved declaration
	 * @return list<bool> native string parameters, including internal constructors
	 */
	private function parameters(string $target): array {
		if ($this->dispatch->isUnit($target) || str_contains($target, '::') && isset($this->index->classes[explode('::', $target)[0]]['methods'][strtolower(explode('::', $target)[1])])) {
			return array_map(static fn (Node\Param $param): bool => self::string($param->type), $this->index->unit($target)['node']->params);
		}
		if (!str_contains($target, '::')) {
			return [];
		}
		[$class, $method] = explode('::', str_replace('php-core ', '', $target));
		if (!(class_exists($class, false) || interface_exists($class, false)) || !(new ReflectionClass($class))->isInternal() || !method_exists($class, $method)) {
			return [];
		}
		return array_map(static fn (ReflectionParameter $param): bool => array_filter($param->getType() instanceof ReflectionUnionType ? $param->getType()->getTypes() : [$param->getType()],
			static fn ($type): bool => $type instanceof ReflectionNamedType && $type->getName() === 'string') !== [], (new ReflectionMethod($class, $method))->getParameters());
	}

	/**
	 * @param Node|null $type native declaration
	 * @return bool a nullable string declaration also coerces an object
	 */
	private static function string(?Node $type): bool {
		return $type instanceof Node\UnionType && array_filter($type->types, self::string(...)) !== [] || $type instanceof Node\Identifier && $type->toLowerString() === 'string' || $type instanceof Node\NullableType && self::string($type->type);
	}

	/**
	 * @param string $path immutable pinned file
	 * @return bool whether PHP's weak scalar declaration rules apply
	 */
	private function weak(string $path): bool {
		if (!array_key_exists($path, $this->weakFiles)) {
			$ast = (new ParserFactory())->createForHostVersion()->parse(file_get_contents($path)) ?? [];
			$this->weakFiles[$path] = true;
			foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\Declare_::class) as $declare) {
				foreach ($declare->declares as $item) {
					if ($item->key->name === 'strict_types' && $item->value instanceof Node\Scalar\Int_ && $item->value->value === 1) {
						$this->weakFiles[$path] = false;
					}
				}
			}
		}
		return $this->weakFiles[$path];
	}
}
