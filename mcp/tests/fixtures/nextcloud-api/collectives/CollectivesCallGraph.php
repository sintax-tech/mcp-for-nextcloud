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
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Every Collectives method an entry point reaches, read from a pinned source tree without running it. The source is
 * parsed, and every call node of a reached body (method, nullsafe, static, `new`, function) is either resolved with
 * certainty or reported as an unknown form, `file:line form`: there is no call shape the reader skips.
 *
 * A receiver is resolved only from a stated type: `$this`, a typed or docblock-typed property or parameter, `new X`,
 * the declared or docblock return of a resolved call (in the app, or in the pinned types given as $types), the one
 * type every `return` of a body without a stated return has, a caught exception, an inline `@var`, an element of an
 * array of one class (foreach, `[]`, a closure given to array_map, array_filter or usort, the array functions that
 * keep elements), `Server::get(X::class)`. A variable assigned in several places has the union of those types.
 *
 * Unknown forms: an untyped value, property or return, a container lookup, a callable that is not a closure written
 * in place, a dynamic name, magic __call, event dispatch, a class of the app missing from the tree, eval, and an
 * include other than of the app's own data files. A call reaches the method the receiver's class runs and every
 * override in an app class extending or implementing its type. Calls that leave the app are leaves, `Type::method`
 * or `function name`; the caller checks those against an explicit allowlist.
 */
final class CollectivesCallGraph {
	/** Functions that call what they are given: safe only with a closure written in place, whose body is read. */
	private const CALLING = ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'array_reduce', 'array_walk', 'array_walk_recursive',
		'usort', 'uasort', 'uksort', 'iterator_apply', 'preg_replace_callback', 'register_shutdown_function', 'forward_static_call'];
	/** Array functions whose closure receives elements: callback argument, array argument, how many parameters get one. */
	private const ITERATING = ['array_map' => [0, 1, 1], 'array_filter' => [1, 0, 1], 'usort' => [1, 0, 2], 'uasort' => [1, 0, 2]];
	/** Array functions that give back elements of the arrays at these arguments, so the element type is kept. */
	private const KEEPING = ['array_values' => [0], 'array_filter' => [0], 'array_slice' => [0], 'array_unique' => [0], 'array_reverse' => [0],
		'array_combine' => [1], 'array_merge' => [0, 1, 2, 3]];
	/** Methods that run listeners or services the source does not name. */
	private const HIDDEN = ['dispatch', 'dispatchTyped', 'emit', 'query', 'resolve'];
	/** Types whose get() is a container lookup. */
	private const CONTAINERS = ['Psr\\Container\\ContainerInterface', 'OCP\\IContainer', 'OCP\\IServerContainer', 'OCP\\AppFramework\\IAppContainer'];

	/** @var array<string, array{outside:bool, file:string, path:string, extends:?string, implements:list<string>, traits:list<string>, props:array<string,string>, methods:array<string,array{node:Stmt\ClassMethod, returns:?string, params:array<string,string>}>}> */
	private array $classes = [];
	/** @var array<string, array{parents:list<string>, props:array<string,string>, returns:array<string,?string>}> the type-only tree */
	private array $types = [];
	/** @var array<string, string> the root namespace of each followed tree (`OCA\\App\\`), with a file of it */
	private array $namespaces = [];
	/** @var array<string, array<string, string>> the imports of the file of each class, for docblock names */
	private array $imports = [];
	/** @var array<string, true> methods whose return is being read, so a recursion gives no type */
	private array $reading = [];
	private Standard $printer;

	/**
	 * @param string|array<string, string> $lib the app's lib/ directory, whose bodies are followed; or several, by the
	 *   prefix their files are reported with (the app's own with '')
	 * @param string|null $types a tree of pinned classes and interfaces (OCP, the Circles classes the app calls) read
	 *   for types: their calls are leaves, never followed
	 */
	public function __construct(string|array $lib, ?string $types = null) {
		$this->printer = new Standard();
		$parser = (new ParserFactory())->createForHostVersion();
		$roots = array_map(static fn (string $dir): array => [$dir, false], is_string($lib) ? ['' => $lib] : $lib);
		if ($types !== null) {
			$roots['(types) '] = [$types, true];
		}
		foreach ($roots as $prefix => [$root, $typesOnly]) {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$ast = $parser->parse((string)file_get_contents($file->getPathname())) ?? [];
				// Docblocks name classes by the imports of their file.
				$imports = [];
				foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\Use_::class) as $use) {
					foreach ($use->uses as $item) {
						$imports[$item->getAlias()->name] = $item->name->toString();
					}
				}
				$ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
				foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
					if ($class->namespacedName !== null) {
						$this->imports[$class->namespacedName->toString()] = $imports;
						$this->index($class, $prefix . substr($file->getPathname(), strlen($root) + 1), $file->getPathname(), $typesOnly);
					}
				}
			}
		}
	}

	/**
	 * @param list<string> $entries `FQCN::method` entry points
	 * @return array{methods:array<string,string>, leaves:list<string>, unknown:list<string>} reached bodies, the calls
	 *   leaving the app and every form the reader could not resolve (`file:line form`)
	 */
	public function reach(array $entries): array {
		$methods = [];
		$leaves = [];
		$unknown = [];
		$queue = $entries;
		while ($queue !== []) {
			$call = array_shift($queue);
			if (!str_contains($call, '::')) {
				$leaves[$call] = true;
				continue;
			}
			[$class, $method] = explode('::', $call);
			foreach ($this->targets($class, $method, $unknown) as $target) {
				if (!str_contains($target, '::') || !$this->followed(explode('::', $target)[0])) {
					$leaves[$target] = true;
					continue;
				}
				if (isset($methods[$target])) {
					continue;
				}
				[$owner, $name] = explode('::', $target);
				$node = $this->classes[$owner]['methods'][$name]['node'];
				$methods[$target] = $this->printer->prettyPrint([$node]);
				array_push($queue, ...$this->calls($owner, $name, $unknown));
			}
		}
		ksort($methods);
		$leaves = array_keys($leaves);
		sort($leaves);
		$unknown = array_values(array_unique($unknown));
		sort($unknown);
		return ['methods' => $methods, 'leaves' => $leaves, 'unknown' => $unknown];
	}

	/**
	 * Where a call to `$class::$method` can land: the app method the receiver's class declares or inherits, or a leaf on
	 * the first outside class; and, since the object may be of a subclass, every override in an app class that extends
	 * or implements the receiver's type (outside types included). A constructor is the one of the class named.
	 * @param string $class FQCN of the receiver
	 * @param string $method method name
	 * @param list<string> $unknown collects magic calls and missing app classes
	 * @return list<string> `Owner::method` of app bodies, or leaves
	 */
	private function targets(string $class, string $method, array &$unknown): array {
		$targets = $this->landing($class, $method, $unknown);
		if ($method === '__construct') {
			return $targets;
		}
		foreach ($this->classes as $fqcn => $info) {
			if ($info['outside'] || $fqcn === $class || !in_array($class, $this->ancestors($fqcn), true)) {
				continue;
			}
			foreach ([$fqcn, ...$info['traits']] as $holder) {
				if (($this->classes[$holder]['methods'][$method]['node'] ?? null)?->stmts !== null) {
					$targets[] = $holder . '::' . $method;
				}
			}
		}
		return array_values(array_unique($targets));
	}

	/**
	 * @param string $class FQCN of the receiver
	 * @param string $method method name
	 * @param list<string> $unknown collects magic calls and missing app classes
	 * @return list<string> the method the class itself runs: its own or inherited app body, or a leaf outside the app
	 */
	private function landing(string $class, string $method, array &$unknown): array {
		if (!$this->followed($class)) {
			foreach ($this->namespaces as $namespace => $file) {
				if (str_starts_with($class, $namespace)) {
					$unknown[] = $file . ':0 ' . $class . '::' . $method . ' names a class of the app the tree does not have';
					return [];
				}
			}
			return [$this->outsideOwner($class, $method) . '::' . $method];
		}
		for ($c = $class; $c !== null && $this->followed($c); $c = $this->classes[$c]['extends']) {
			foreach ([$c, ...$this->classes[$c]['traits']] as $holder) {
				if (($this->classes[$holder]['methods'][$method]['node'] ?? null)?->stmts !== null) {
					return [$holder . '::' . $method];
				}
			}
		}
		for ($c = $class; $c !== null; $c = $this->classes[$c]['extends'] ?? null) {
			if (!$this->followed($c)) {
				return [$this->outsideOwner($c, $method) . '::' . $method];
			}
		}
		// An app interface or abstract method: its implementations are added as overrides.
		if (isset($this->classes[$class]['methods'][$method]) || $method === '__construct') {
			return [];
		}
		$unknown[] = $this->classes[$class]['file'] . ':0 ' . $class . '::' . $method . ' is declared nowhere the reader can see (magic __call)';
		return [];
	}

	/**
	 * @param string $class FQCN
	 * @return bool whether its bodies are followed (a class of the app, not of the type-only tree)
	 */
	private function followed(string $class): bool {
		return isset($this->classes[$class]) && !$this->classes[$class]['outside'];
	}

	/**
	 * @param string $fqcn an app class
	 * @return list<string> every class and interface it extends or implements, in the app or outside
	 */
	private function ancestors(string $fqcn): array {
		$found = [];
		$todo = [$fqcn];
		while ($todo !== []) {
			$c = array_shift($todo);
			$next = isset($this->classes[$c]) ? array_filter([$this->classes[$c]['extends'], ...$this->classes[$c]['implements']]) : ($this->types[$c]['parents'] ?? []);
			foreach ($next as $parent) {
				if (!in_array($parent, $found, true)) {
					$found[] = $parent;
					$todo[] = $parent;
				}
			}
		}
		return $found;
	}

	/**
	 * @param string $class an outside class
	 * @param string $method method name
	 * @return string the outside class or interface that declares it, from the type-only tree; the class itself otherwise
	 */
	private function outsideOwner(string $class, string $method): string {
		$todo = [$class];
		while ($todo !== []) {
			$c = array_shift($todo);
			if (array_key_exists($method, $this->types[$c]['returns'] ?? [])) {
				return $c;
			}
			array_push($todo, ...($this->types[$c]['parents'] ?? []));
		}
		return $class;
	}

	/**
	 * @param string $class owner FQCN
	 * @param string $method method name
	 * @param list<string> $unknown collects the unknown forms of the body, with file and line
	 * @return list<string> the calls of the body, `Class::method` or `function name`
	 */
	private function calls(string $class, string $method, array &$unknown): array {
		$node = $this->classes[$class]['methods'][$method]['node'];
		$file = $this->classes[$class]['file'];
		$locals = $this->locals($class, $method);
		$calls = [];
		$miss = function (Node $at, string $form) use (&$unknown, $file): void {
			$unknown[] = $file . ':' . $at->getStartLine() . ' ' . $form;
		};
		foreach ((new NodeFinder())->find($node->stmts ?? [], static fn (Node $n): bool => true) as $n) {
			if ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall) {
				$text = $this->printer->prettyPrintExpr($n->var) . '->' . ($n->name instanceof Node\Identifier ? $n->name->name : '{dynamic}') . '(';
				if (!$n->name instanceof Node\Identifier) {
					$miss($n, 'dynamic method name ' . $text);
					continue;
				}
				$types = $this->typeOf($class, $n->var, $locals);
				if ($types === [] || in_array(null, $types, true)) {
					$miss($n, ($n->var instanceof Expr\PropertyFetch ? 'untyped property ' : 'untyped value ') . $text);
					continue;
				}
				foreach ($types as $type) {
					if (str_ends_with($type, '[]') || $type === '') {
						$miss($n, 'call on a value that is not an object ' . $text);
					} elseif (in_array($n->name->name, self::HIDDEN, true)) {
						$miss($n, 'event dispatch or lookup ' . $text);
					} elseif ($n->name->name === 'get' && array_intersect([$type, ...$this->ancestors($type)], self::CONTAINERS) !== []) {
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
				$target = $this->name($class, $n->class);
				if ($target === 'OCP\\Server' && $n->name->name === 'get' && $this->literalClass($n->args[0] ?? null) === null) {
					$miss($n, 'Server::get( of a class not written in place');
					continue;
				}
				$calls[] = $target . '::' . $n->name->name;
			} elseif ($n instanceof Expr\New_) {
				if (!$n->class instanceof Node\Name) {
					$miss($n, $n->class instanceof Stmt\Class_ ? 'anonymous class' : 'new of a dynamic class');
					continue;
				}
				$calls[] = $this->name($class, $n->class) . '::__construct';
			} elseif ($n instanceof Expr\FuncCall) {
				if (!$n->name instanceof Node\Name) {
					$miss($n, 'call of a callable value ' . $this->printer->prettyPrintExpr($n->name) . '(');
					continue;
				}
				$function = $n->name->toString();
				if (in_array(strtolower($function), self::CALLING, true)) {
					$closures = array_filter($n->args, static fn ($arg): bool => $arg instanceof Node\Arg && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction));
					if ($closures === []) {
						$miss($n, $function . '( with a callable that is not written in place');
					}
				}
				$calls[] = 'function ' . $function;
			} elseif ($n instanceof Expr\Include_ && $this->includesOnlyData($class, $this->assigned($node, $n->expr))) {
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
	 * @param Stmt\ClassMethod $method a method
	 * @param Expr $expr an expression in it
	 * @return Expr the one expression a variable is assigned in the method, or the expression itself
	 */
	private function assigned(Stmt\ClassMethod $method, Expr $expr): Expr {
		if (!$expr instanceof Expr\Variable) {
			return $expr;
		}
		$sets = (new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Expr\Assign && $n->var instanceof Expr\Variable
			&& $n->var->name === $expr->name);
		return count($sets) === 1 ? $sets[0]->expr : $expr;
	}

	/**
	 * An include of the app's own data files: the path is literals, `__DIR__` and variables, and every file of the
	 * pinned tree it can name is only `return [literals];`.
	 * @param string $class FQCN of the code
	 * @param Expr $path the included path
	 * @return bool whether the include is resolved that way
	 */
	private function includesOnlyData(string $class, Expr $path): bool {
		$parts = [];
		for ($e = $path; $e instanceof Expr\BinaryOp\Concat; $e = $e->left) {
			array_unshift($parts, $e->right);
		}
		array_unshift($parts, $e);
		$pattern = '';
		foreach ($parts as $part) {
			$pattern .= match (true) {
				$part instanceof Node\Scalar\MagicConst\Dir => dirname($this->classes[$class]['path']),
				$part instanceof Node\Scalar\String_ => $part->value,
				$part instanceof Expr\Variable => '*',
				default => "\0",
			};
		}
		if (str_contains($pattern, "\0") || str_contains($pattern, '..') || !str_starts_with($pattern, dirname($this->classes[$class]['path']))) {
			return false;
		}
		$parser = (new ParserFactory())->createForHostVersion();
		foreach (glob($pattern) ?: [] as $file) {
			$ast = array_values(array_filter($parser->parse((string)file_get_contents($file)) ?? [], static fn (Node $n): bool => !$n instanceof Stmt\Nop));
			if (count($ast) !== 1 || !$ast[0] instanceof Stmt\Return_ || !$ast[0]->expr instanceof Expr\Array_
				|| array_filter($ast[0]->expr->items, static fn ($item): bool => !$item->value instanceof Node\Scalar) !== []) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The types of the locals of a body, flow-insensitive: a union per variable over every assignment; null in a union
	 * is a value of unknown type, '' one that is no object.
	 * @param string $class owner FQCN
	 * @param string $method method name
	 * @return array<string, list<?string>> variable name to its possible types
	 */
	private function locals(string $class, string $method): array {
		$info = $this->classes[$class]['methods'][$method];
		$finder = new NodeFinder();
		$all = $finder->find($info['node']->stmts ?? [], static fn (Node $n): bool => true);
		// Every variable the body names starts with no type yet; assignments that read other locals are settled by
		// repeating until nothing changes, and a variable still without a type in the end is unknown.
		$start = array_fill_keys(array_map(static fn (Expr\Variable $v): string => $v->name,
			array_filter($finder->findInstanceOf($info['node']->stmts ?? [], Expr\Variable::class), static fn (Expr\Variable $v): bool => is_string($v->name))), []);
		unset($start['this']);
		$start = array_merge($start, array_map(static fn (string $type): array => [$type], $info['params']));
		$locals = $start;
		for ($round = 0; $round < 8; $round++) {
			$next = $start;
			foreach ($all as $n) {
				if ($n instanceof Stmt\Expression && $n->expr instanceof Expr\Assign && $n->expr->var instanceof Expr\Variable && is_string($n->expr->var->name)
					&& ($stated = $this->inlineVar($class, $n, $n->expr->var->name)) !== null) {
					$next[$n->expr->var->name] = [...($next[$n->expr->var->name] ?? []), $stated];
					$n->expr->setAttribute('stated', true);
				} elseif ($n instanceof Expr\Assign && $n->var instanceof Expr\ArrayDimFetch && $n->var->var instanceof Expr\Variable && is_string($n->var->var->name)) {
					// `$list[] = $x` makes $list an array of the type of $x.
					$next[$n->var->var->name] = [...($next[$n->var->var->name] ?? []), ...array_map(static fn (?string $t): ?string
						=> $t === null || $t === '' || str_ends_with($t, '[]') ? ($t === null ? null : '') : $t . '[]', $this->typeOf($class, $n->expr, $locals))];
				} elseif ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name && isset(self::ITERATING[strtolower($n->name->toString())])) {
					// A closure given to array_map, array_filter or usort receives the elements of the array.
					[$callback, $array, $count] = self::ITERATING[strtolower($n->name->toString())];
					$fn = $n->args[$callback]->value ?? null;
					if (($fn instanceof Expr\Closure || $fn instanceof Expr\ArrowFunction) && isset($n->args[$array]) && count($n->args) === 2) {
						$elements = array_map(self::element(...),
							$this->typeOf($class, $n->args[$array]->value, $locals));
						$fn->setAttribute('elements', [$elements, $count]);
					}
				} elseif (($n instanceof Expr\Assign || $n instanceof Expr\AssignOp\Coalesce) && !$n->hasAttribute('stated')) {
					foreach ($this->targetsOf($n->var) as $name) {
						$next[$name] = [...($next[$name] ?? []), ...($n->var instanceof Expr\Variable ? $this->typeOf($class, $n->expr, $locals) : [null])];
					}
				} elseif ($n instanceof Stmt\Foreach_) {
					$elements = array_map(self::element(...), $this->typeOf($class, $n->expr, $locals));
					foreach ($this->targetsOf($n->valueVar) as $name) {
						$next[$name] = [...($next[$name] ?? []), ...($n->valueVar instanceof Expr\Variable ? $elements : [null])];
					}
					foreach ($n->keyVar === null ? [] : $this->targetsOf($n->keyVar) as $name) {
						$next[$name][] = '';
					}
				} elseif ($n instanceof Stmt\Catch_ && $n->var !== null && is_string($n->var->name)) {
					foreach ($n->types as $type) {
						$next[$n->var->name][] = $this->name($class, $type);
					}
				} elseif ($n instanceof Expr\Closure || $n instanceof Expr\ArrowFunction) {
					[$elements, $count] = $n->getAttribute('elements', [[null], 0]);
					foreach ($n->params as $i => $param) {
						$next[$param->var->name] = [...($next[$param->var->name] ?? []),
							...($param->type instanceof Node\Name ? [$this->name($class, $param->type)] : ($i < $count ? $elements : [null]))];
					}
					foreach ($n instanceof Expr\Closure ? $n->uses : [] as $use) {
						$next[$use->var->name] ??= [];
					}
				} elseif ($n instanceof Expr\List_ || $n instanceof Stmt\Global_ || $n instanceof Stmt\Static_) {
					foreach ($finder->findInstanceOf([$n], Expr\Variable::class) as $var) {
						if (is_string($var->name)) {
							$next[$var->name][] = null;
						}
					}
				}
			}
			$next = array_map(self::union(...), $next);
			if ($next === $locals) {
				break;
			}
			$locals = $next;
		}
		return array_map(static fn (array $types): array => $types === [] ? [null] : $types, $locals);
	}

	/**
	 * @param Expr $var the left side of an assignment
	 * @return list<string> the plain variables it writes
	 */
	private function targetsOf(Expr $var): array {
		if ($var instanceof Expr\Variable) {
			return is_string($var->name) ? [$var->name] : [];
		}
		if ($var instanceof Expr\List_ || $var instanceof Expr\Array_) {
			return array_merge(...array_map(fn ($item): array => $item === null ? [] : $this->targetsOf($item->value), $var->items), ...[[]]);
		}
		return [];
	}

	/**
	 * @param string $class FQCN of the code
	 * @param Stmt\Expression $statement an assignment statement
	 * @param string $name the variable it assigns
	 * @return string|null the type an inline `@var` docblock states for it
	 */
	private function inlineVar(string $class, Stmt\Expression $statement, string $name): ?string {
		$doc = $statement->getDocComment()?->getText() ?? '';
		return preg_match('/@var\s+(\S+)\s+\$' . $name . '\b/', $doc, $m) ? $this->docType($m[1], fn (string $t): string => $this->docName($class, $t)) : null;
	}

	/**
	 * @param string $class FQCN of the code
	 * @param Expr $expr an expression
	 * @param array<string, list<?string>> $locals the local types known so far
	 * @return list<?string> its possible types: FQCN, `FQCN[]`, '' for no object, null for unknown
	 */
	private function typeOf(string $class, Expr $expr, array $locals): array {
		return match (true) {
			$expr instanceof Expr\Variable => $expr->name === 'this' ? [$class] : (is_string($expr->name) ? ($locals[$expr->name] ?? [null]) : [null]),
			$expr instanceof Expr\New_ => [$expr->class instanceof Node\Name ? $this->name($class, $expr->class) : null],
			$expr instanceof Expr\Clone_, $expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp\Coalesce
				=> $this->typeOf($class, $expr instanceof Expr\Clone_ ? $expr->expr : $expr->expr, $locals),
			$expr instanceof Expr\Ternary => self::union([...$this->typeOf($class, $expr->if ?? $expr->cond, $locals), ...$this->typeOf($class, $expr->else, $locals)]),
			$expr instanceof Expr\BinaryOp\Coalesce => self::union([...$this->typeOf($class, $expr->left, $locals), ...$this->typeOf($class, $expr->right, $locals)]),
			$expr instanceof Expr\Array_ && $expr->items === [] => ['[]'],
			$expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && isset(self::KEEPING[strtolower($expr->name->toString())])
				=> self::union(array_merge(...array_map(fn (int $i): array => isset($expr->args[$i]) && $expr->args[$i] instanceof Node\Arg
					? $this->typeOf($class, $expr->args[$i]->value, $locals) : [null], self::KEEPING[strtolower($expr->name->toString())]))),
			$expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch => $this->propertyType($class, $expr, $locals),
			$expr instanceof Expr\ArrayDimFetch => array_map(self::element(...),
				$this->typeOf($class, $expr->var, $locals)),
			$expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->callType($class, $expr, $locals),
			$expr instanceof Expr\StaticCall => $this->staticType($class, $expr),
			$expr instanceof Node\Scalar, $expr instanceof Expr\Array_, $expr instanceof Expr\ConstFetch, $expr instanceof Expr\ClassConstFetch,
			$expr instanceof Expr\BinaryOp, $expr instanceof Expr\BooleanNot, $expr instanceof Expr\Cast, $expr instanceof Expr\Isset_,
			$expr instanceof Expr\Empty_, $expr instanceof Expr\Instanceof_, $expr instanceof Expr\UnaryMinus, $expr instanceof Expr\Closure,
			$expr instanceof Expr\ArrowFunction, $expr instanceof Expr\FuncCall, $expr instanceof Expr\Match_ => [''],
			default => [null],
		};
	}

	/**
	 * @param string|null $type a possible type
	 * @return string|null the type of an element when it is an array of a class; null otherwise
	 */
	private static function element(?string $type): ?string {
		return $type !== null && $type !== '[]' && str_ends_with($type, '[]') ? substr($type, 0, -2) : null;
	}

	/**
	 * @param list<?string> $types possible types
	 * @return list<?string> without repeats; an empty array (`[]`) adds nothing to an array of a class
	 */
	private static function union(array $types): array {
		$types = array_values(array_unique($types, SORT_REGULAR));
		$arrays = array_filter($types, static fn (?string $t): bool => $t !== null && $t !== '[]' && str_ends_with($t, '[]'));
		return $arrays === [] ? $types : array_values(array_diff($types, ['[]']));
	}

	/**
	 * @param string $class FQCN of the code
	 * @param Expr\PropertyFetch|Expr\NullsafePropertyFetch $expr a property read
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the property's stated type on each possible receiver
	 */
	private function propertyType(string $class, Expr $expr, array $locals): array {
		if (!$expr->name instanceof Node\Identifier) {
			return [null];
		}
		return array_map(fn (?string $t): ?string => $t === null || $t === '' ? null : ($this->props($t)[$expr->name->name] ?? null), $this->typeOf($class, $expr->var, $locals));
	}

	/**
	 * @param string $class FQCN of the code
	 * @param Expr\MethodCall|Expr\NullsafeMethodCall $expr a method call
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the stated return on each possible receiver
	 */
	private function callType(string $class, Expr $expr, array $locals): array {
		if (!$expr->name instanceof Node\Identifier) {
			return [null];
		}
		return array_map(fn (?string $t): ?string => $t === null || $t === '' || str_ends_with($t, '[]') ? null : $this->returnOf($t, $expr->name->name),
			$this->typeOf($class, $expr->var, $locals));
	}

	/**
	 * @param string $class FQCN of the code
	 * @param Expr\StaticCall $expr a static call
	 * @return list<?string> its stated return; `Server::get(X::class)` is an X
	 */
	private function staticType(string $class, Expr\StaticCall $expr): array {
		if (!$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
			return [null];
		}
		$target = $this->name($class, $expr->class);
		if ($target === 'OCP\\Server' && $expr->name->name === 'get') {
			return [$this->literalClass($expr->args[0] ?? null)];
		}
		return [$this->returnOf($target, $expr->name->name)];
	}

	/**
	 * @param Node\Arg|Node\VariadicPlaceholder|null $arg an argument
	 * @return string|null the class it names when it is written in place as `X::class`
	 */
	private function literalClass(?Node $arg): ?string {
		return $arg instanceof Node\Arg && $arg->value instanceof Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
			&& $arg->value->name instanceof Node\Identifier && $arg->value->name->name === 'class' ? $arg->value->class->toString() : null;
	}

	/**
	 * @param string $class FQCN
	 * @param string $method method name
	 * @return string|null its stated return class (or `X[]`), from the app or the type-only tree; null when unstated
	 */
	private function returnOf(string $class, string $method): ?string {
		for ($c = $class; $c !== null && isset($this->classes[$c]); $c = $this->classes[$c]['extends']) {
			if (isset($this->classes[$c]['methods'][$method])) {
				return $this->classes[$c]['methods'][$method]['returns'] ?? $this->returned($c, $method);
			}
		}
		$owner = $this->outsideOwner($c ?? $class, $method);
		return $this->types[$owner]['returns'][$method] ?? null;
	}

	/**
	 * The type a method with no stated class return gives back: the one type every `return` of its body has.
	 * @param string $class owner FQCN
	 * @param string $method method name
	 * @return string|null that type; null when a return is untyped, when they differ, or on recursion
	 */
	private function returned(string $class, string $method): ?string {
		$key = $class . '::' . $method;
		$node = $this->classes[$class]['methods'][$method]['node'];
		if (isset($this->reading[$key]) || $node->stmts === null) {
			return null;
		}
		$this->reading[$key] = true;
		try {
			$locals = $this->locals($class, $method);
			$types = [];
			foreach (self::returns($node->stmts) as $return) {
				array_push($types, ...($return->expr === null ? [''] : $this->typeOf($class, $return->expr, $locals)));
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
				if ($node->$name instanceof Node || is_array($node->$name)) {
					array_push($found, ...self::returns(array_filter(is_array($node->$name) ? $node->$name : [$node->$name], static fn ($n): bool => $n instanceof Node)));
				}
			}
		}
		return $found;
	}

	/**
	 * @param string $class FQCN
	 * @return array<string, string> its stated property types, inherited ones included, in the app or outside
	 */
	private function props(string $class): array {
		$props = [];
		foreach ([$class, ...$this->ancestors($class)] as $c) {
			$props += $this->classes[$c]['props'] ?? $this->types[$c]['props'] ?? [];
		}
		return $props;
	}

	/**
	 * @param string $class FQCN of the code
	 * @param Node\Name $name a resolved class name in it
	 * @return string the FQCN it means, self/static/parent included
	 */
	private function name(string $class, Node\Name $name): string {
		return match (strtolower($name->toString())) {
			'self', 'static' => $class,
			'parent' => (string)($this->classes[$class]['extends'] ?? ''),
			default => $name->toString(),
		};
	}

	/**
	 * @param string $class FQCN of the code
	 * @param string $name a class name written in a docblock
	 * @return string the FQCN it means, with the imports of the file the class was read from
	 */
	private function docName(string $class, string $name): string {
		if (in_array(strtolower($name), ['self', 'static', '$this'], true)) {
			return $class;
		}
		if (str_starts_with($name, '\\')) {
			return ltrim($name, '\\');
		}
		$head = explode('\\', $name)[0];
		return isset($this->imports[$class][$head]) ? $this->imports[$class][$head] . substr($name, strlen($head))
			: substr($class, 0, (int)strrpos($class, '\\') + 1) . $name;
	}

	/**
	 * A type stated in a docblock, kept only when it names one class (`X`, `?X`, `X|null`, `$this`) or an array of one
	 * class (`X[]`, `list<X>`, `array<k, X>`, kept as `X[]`); anything else is no type.
	 * @param string $doc the docblock type
	 * @param callable(string):string $resolve resolves a class name of the file
	 * @return string|null the FQCN, `FQCN[]` for an array of it, or null
	 */
	private function docType(string $doc, callable $resolve): ?string {
		$parts = array_values(array_diff(explode('|', ltrim($doc, '?')), ['null']));
		if (count($parts) !== 1) {
			return null;
		}
		$type = $parts[0];
		$array = '';
		if (preg_match('/^(?:list|array|iterable)<(?:[\w\\\\]+,\s*)?([\w\\\\]+)>$/', $type, $m) || preg_match('/^([\w\\\\]+)\[\]$/', $type, $m)) {
			[$type, $array] = [$m[1], '[]'];
		}
		$short = substr((string)strrchr('\\' . $type, '\\'), 1);
		if ($type !== '$this' && preg_match('/^[A-Z]\w*$/', $short) !== 1) {
			return null;
		}
		return $resolve($type) . $array;
	}

	/**
	 * @param Node\ComplexType|Node\Identifier|Node\Name|null $type a declared type
	 * @param string $class FQCN of the code
	 * @return string|null the class it names, nullable allowed; null for a scalar, a union or none
	 */
	private function declared(?Node $type, string $class): ?string {
		if ($type instanceof Node\NullableType) {
			$type = $type->type;
		}
		if ($type instanceof Node\UnionType) {
			$named = array_values(array_filter($type->types, static fn (Node $t): bool => !($t instanceof Node\Identifier && $t->toLowerString() === 'null')));
			$type = count($named) === 1 ? $named[0] : null;
		}
		return $type instanceof Node\Name ? $this->name($class, $type) : null;
	}

	/**
	 * @param Stmt\ClassLike $node a class, interface or trait
	 * @param string $file its path in the tree, as reported
	 * @param string $path its path on disk
	 * @param bool $typesOnly read declared types only
	 * @return void
	 */
	private function index(Stmt\ClassLike $node, string $file, string $path, bool $typesOnly): void {
		$fqcn = $node->namespacedName->toString();
		$resolve = fn (string $t): string => $this->docName($fqcn, $t);
		$parents = match (true) {
			$node instanceof Stmt\Class_ => array_filter([$node->extends?->toString(), ...array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements)]),
			$node instanceof Stmt\Interface_ => array_map(static fn (Node\Name $n): string => $n->toString(), $node->extends),
			default => [],
		};
		$props = [];
		foreach ($node->getProperties() as $property) {
			$doc = $property->getDocComment()?->getText() ?? '';
			$type = $this->declared($property->type, $fqcn) ?? (preg_match('/@var\s+(\S+)/', $doc, $m) ? $this->docType($m[1], $resolve) : null);
			foreach ($property->props as $prop) {
				if ($type !== null) {
					$props[$prop->name->name] = $type;
				}
			}
		}
		$methods = [];
		$returns = [];
		foreach ($node->getMethods() as $method) {
			$doc = $method->getDocComment()?->getText() ?? '';
			$returns[$method->name->name] = $this->declared($method->returnType, $fqcn) ?? (preg_match('/@return\s+(\S+)/', $doc, $m) ? $this->docType($m[1], $resolve) : null);
			$params = [];
			preg_match_all('/@param\s+(\S+)\s+(?:\.\.\.)?\$(\w+)/', $doc, $ps, PREG_SET_ORDER);
			foreach ($ps as [, $type, $param]) {
				if (($type = $this->docType($type, $resolve)) !== null) {
					$params[$param] = $type;
				}
			}
			foreach ($method->params as $param) {
				$type = $this->declared($param->type, $fqcn);
				if ($type !== null && is_string($param->var->name)) {
					$params[$param->var->name] = $type;
					// A promoted parameter is a typed property.
					if ($param->flags !== 0) {
						$props[$param->var->name] = $type;
					}
				}
			}
			// An untyped property the constructor sets from a typed parameter has that parameter's type.
			if ($method->name->toLowerString() === '__construct') {
				foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
					if ($assign->var instanceof Expr\PropertyFetch && $assign->var->var instanceof Expr\Variable && $assign->var->var->name === 'this'
						&& $assign->var->name instanceof Node\Identifier && $assign->expr instanceof Expr\Variable && is_string($assign->expr->name)
						&& isset($params[$assign->expr->name])) {
						$props[$assign->var->name->name] ??= $params[$assign->expr->name];
					}
				}
			}
			$methods[$method->name->name] = ['node' => $method, 'returns' => $returns[$method->name->name], 'params' => $params];
		}
		if ($typesOnly) {
			$this->types[$fqcn] = ['parents' => array_values($parents), 'props' => $props, 'returns' => $returns];
		}
		$traits = [];
		foreach ($node->getTraitUses() as $use) {
			foreach ($use->traits as $trait) {
				$traits[] = $trait->toString();
			}
		}
		$extends = $node instanceof Stmt\Class_ ? $node->extends?->toString() : null;
		if (!$typesOnly) {
			$this->namespaces[implode('\\', array_slice(explode('\\', $fqcn), 0, 2)) . '\\'] ??= $file;
		}
		// A class of the type-only tree keeps its bodies too, so a return it does not state is read from them.
		$this->classes[$fqcn] = ['outside' => $typesOnly, 'file' => $file, 'path' => $path, 'extends' => $extends, 'implements' => array_values(array_diff($parents, [$extends])), 'traits' => $traits,
			'props' => $props, 'methods' => $methods];
	}
}
