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
use PhpParser\PrettyPrinter\Standard;

/**
 * The calls of one body, each resolved from a stated type or reported as an unknown form, `file:line form`. Every
 * node is visited, so no call shape is skipped: method, nullsafe and static calls, `new`, functions, includes, and
 * the implicit calls PHP makes when a followed class declares a magic method (property access, clone, string use).
 */
final class CallGraphCalls {
	/** Functions that call what they are given: safe only with a closure written in place, whose body is read. */
	private const CALLING = ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'array_reduce', 'array_walk', 'array_walk_recursive',
		'usort', 'uasort', 'uksort', 'iterator_apply', 'preg_replace_callback', 'register_shutdown_function', 'forward_static_call'];
	/** Methods that run listeners or services the source does not name. */
	private const HIDDEN = ['dispatch', 'dispatchtyped', 'emit', 'query', 'resolve'];
	/** Types whose get() is a container lookup. */
	private const CONTAINERS = ['Psr\\Container\\ContainerInterface', 'OCP\\IContainer', 'OCP\\IServerContainer', 'OCP\\AppFramework\\IAppContainer'];

	private Standard $printer;
	private CallGraphMagicUses $magic;
	private CallGraphArguments $arguments;

	/**
	 * @param CallGraphIndex $index the parsed trees
	 * @param CallGraphDispatch $dispatch where calls land, and which magic methods are declared
	 * @param CallGraphTypes $types expression types
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch, private CallGraphTypes $types) {
		$this->printer = new Standard();
		$this->arguments = new CallGraphArguments($index, $dispatch, $types);
		$this->magic = new CallGraphMagicUses($index, $dispatch, $this->arguments);
	}

	/**
	 * @param string $key a unit key
	 * @param list<string> $unknown collects the unknown forms of the body
	 * @return list<string> its calls, `Class::method` or `function name`
	 */
	public function of(string $key, array &$unknown): array {
		$unit = $this->index->unit($key);
		$class = $unit['class'];
		$locals = $this->types->locals->of($key);
		$typeOf = fn (Expr $e): array => $this->types->typeOf($class, $e, $locals);
		$miss = static function (Node $at, string $form) use (&$unknown, $unit): void {
			$unknown[] = $unit['file'] . ':' . $at->getStartLine() . ' ' . $form;
		};
		$calls = [];
		foreach ($unit['node']->params as $param) {
			CallGraphSubset::check($param, $miss);
		}
		foreach ((new NodeFinder())->find($unit['node']->stmts ?? [], static fn (Node $n): bool => true) as $n) {
			CallGraphSubset::check($n, $miss, $typeOf);
			$this->arguments->record($n, $key, $class, $typeOf);
			array_push($calls, ...$this->magic->calls($n, $typeOf, $miss, $key));
			if ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall) {
				$text = $this->printer->prettyPrintExpr($n->var) . '->' . ($n->name instanceof Node\Identifier ? $n->name->name : '{dynamic}') . '(';
				if (!$n->name instanceof Node\Identifier) {
					$miss($n, 'dynamic method name ' . $text);
					continue;
				}
				$receivers = $typeOf($n->var);
				if (in_array(null, $receivers, true)) {
					$miss($n, ($n->var instanceof Expr\PropertyFetch ? 'untyped property ' : 'untyped value ') . $text);
					continue;
				}
				foreach ($receivers as $type) {
					if (!CallGraphTypes::isObject($type)) {
						$miss($n, 'call on a value that is not an object ' . $text);
					} elseif (in_array($n->name->toLowerString(), self::HIDDEN, true)) {
						$miss($n, 'event dispatch or lookup ' . $text);
					} elseif ($n->name->toLowerString() === 'get' && array_intersect([$type, ...$this->index->ancestors($type)], self::CONTAINERS) !== []) {
						$miss($n, 'container lookup ' . $text);
					} else {
						$calls[] = $type . '::' . $n->name->name;
					}
				}
			} elseif ($n instanceof Expr\StaticCall) {
				if (!$n->class instanceof Node\Name || !$n->name instanceof Node\Identifier) {
					$miss($n, 'dynamic static call ' . $this->printer->prettyPrintExpr($n));
					continue;
				}
				$target = $this->index->name($class, $n->class);
				if ($target === 'OCP\\Server' && $n->name->toLowerString() === 'get' && CallGraphTypes::literalClass($n->args[0] ?? null) === null) {
					$miss($n, 'Server::get( of a class not written in place');
					continue;
				}
				$calls[] = 'static ' . $target . '::' . $n->name->name;
			} elseif ($n instanceof Expr\New_) {
				$created = $n->class instanceof Stmt\Class_ ? [null] : $this->types->typeOf($class, $n, $locals);
				if (in_array(null, $created, true)) {
					$miss($n, $n->class instanceof Stmt\Class_ ? 'anonymous class' : 'new of a dynamic class');
					continue;
				}
				array_push($calls, ...array_map(static fn (string $c): string => $c . '::__construct', $created));
			} elseif ($n instanceof Expr\FuncCall) {
				if (!$n->name instanceof Node\Name) {
					if (array_filter($typeOf($n->name), CallGraphTypes::isObject(...)) === []) {
						$miss($n, 'call of a callable value ' . $this->printer->prettyPrintExpr($n->name) . '(');
					}
					continue;
				}
				$function = $this->index->functionName($n->name);
				if (!isset($this->index->functions[$function])) {
					CallGraphFunctions::check($n, $miss);
				}
				$inPlace = array_filter($n->args, static fn ($arg): bool => $arg instanceof Node\Arg && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction));
				if (in_array(strtolower($function), self::CALLING, true) && $inPlace === []) {
					$miss($n, $function . '( with a callable that is not written in place');
				}
				$calls[] = 'function ' . $function;
			} elseif ($n instanceof Expr\Include_ && $this->includesOnlyData($unit, $this->assigned($unit['node'], $n->expr))) {
				continue;
			} elseif ($n instanceof Expr\Include_ || $n instanceof Expr\Eval_ || $n instanceof Expr\ShellExec) {
				$miss($n, 'include, eval or shell');
			} elseif ($n instanceof Expr\Variable && !is_string($n->name)) {
				$miss($n, 'variable variable');
			} elseif ($n instanceof Node\VariadicPlaceholder) {
				$miss($n, 'first-class callable');
			}
		}
		return array_values(array_unique($calls));
	}

	/**
	 * @param Stmt\ClassMethod|Stmt\Function_ $fn a body
	 * @param Expr $expr an expression in it
	 * @return Expr the one expression a variable is assigned in the body, or the expression itself
	 */
	private function assigned(Node $fn, Expr $expr): Expr {
		if (!$expr instanceof Expr\Variable) {
			return $expr;
		}
		$sets = (new NodeFinder())->find($fn->stmts ?? [], static fn (Node $n): bool => $n instanceof Expr\Assign && $n->var instanceof Expr\Variable
			&& $n->var->name === $expr->name);
		return count($sets) === 1 ? $sets[0]->expr : $expr;
	}

	/**
	 * An include of the tree's own data files: the path is literals, `__DIR__` and variables, and every file of the
	 * pinned tree it can name is only `return [literals];`.
	 * @param array{path:string} $unit the body
	 * @param Expr $path the included path
	 * @return bool whether the include is resolved that way
	 */
	private function includesOnlyData(array $unit, Expr $path): bool {
		$parts = [];
		for ($e = $path; $e instanceof Expr\BinaryOp\Concat; $e = $e->left) {
			array_unshift($parts, $e->right);
		}
		array_unshift($parts, $e);
		$dir = dirname($unit['path']);
		$pattern = '';
		foreach ($parts as $part) {
			$pattern .= match (true) {
				$part instanceof Node\Scalar\MagicConst\Dir => $dir,
				$part instanceof Node\Scalar\String_ => $part->value,
				$part instanceof Expr\Variable => '*',
				default => "\0",
			};
		}
		if (str_contains($pattern, "\0") || str_contains($pattern, '..') || !str_starts_with($pattern, $dir)) {
			return false;
		}
		$parser = (new ParserFactory())->createForHostVersion();
		foreach (($files = glob($pattern) ?: []) as $file) {
			$ast = array_values(array_filter($parser->parse((string)file_get_contents($file)) ?? [], static fn (Node $n): bool => !$n instanceof Stmt\Nop));
			if (count($ast) !== 1 || !$ast[0] instanceof Stmt\Return_ || !$ast[0]->expr instanceof Expr\Array_
				|| array_filter($ast[0]->expr->items, static fn ($item): bool => !$item->value instanceof Node\Scalar) !== []) {
				return false;
			}
		}
		return $files !== [];
	}
}
