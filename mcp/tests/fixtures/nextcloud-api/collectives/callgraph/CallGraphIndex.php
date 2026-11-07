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

/**
 * The classes and functions of the trees CollectivesCallGraph reads, parsed once. Followed trees keep every body; the
 * type-only tree (the pinned OCP and Circles classes) is read for its types, and its bodies only to read a return it
 * does not state. A unit is a body the reader can follow: `Class::method`, or `function name@file`.
 */
final class CallGraphIndex {
	/** @var array<string, array{outside:bool, file:string, path:string, extends:?string, parents:list<string>, traits:list<string>, props:array<string,string>, consts:array<string,Expr>, methods:array<string,array{node:Stmt\ClassMethod, returns:?string, params:array<string,string>}>}> */
	public array $classes = [];
	/** @var array<string, list<string>> the followed definitions of each global function, as unit keys */
	public array $functions = [];
	/** @var array<string, array{node:Stmt\Function_, file:string, path:string, params:array<string,string>, returns:?string}> */
	private array $functionUnits = [];
	/** @var array<string, string> the namespace of each followed tree (`OCA\\App\\`), with a file of it */
	public array $namespaces = [];
	/** @var array<string, array<string, string>> the imports of the file of each class, for docblock names */
	private array $imports = [];
	private CallGraphSignatures $signatures;

	/**
	 * @param array<string, string> $lib followed trees by the prefix their files are reported with
	 * @param string|null $types the type-only tree
	 */
	public function __construct(array $lib, ?string $types) {
		$this->signatures = new CallGraphSignatures($this);
		$parser = (new ParserFactory())->createForHostVersion();
		$roots = array_map(static fn (string $dir): array => [$dir, false], $lib);
		if ($types !== null) {
			$roots['(types) '] = [$types, true];
		}
		foreach ($roots as $prefix => [$root, $typesOnly]) {
			$spaces = [];
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$ast = $parser->parse((string)file_get_contents($file->getPathname())) ?? [];
				$imports = [];
				foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\Use_::class) as $use) {
					foreach ($use->uses as $item) {
						$imports[$item->getAlias()->name] = $item->name->toString();
					}
				}
				$ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
				$name = $prefix . substr($file->getPathname(), strlen($root) + 1);
				foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
					if ($class->namespacedName !== null) {
						$this->imports[$class->namespacedName->toString()] = $imports;
						$this->index($class, $name, $file->getPathname(), $typesOnly);
						$spaces[] = $class->namespacedName->toString();
					}
				}
				foreach ($typesOnly ? [] : (new NodeFinder())->findInstanceOf($ast, Stmt\Function_::class) as $function) {
					$this->indexFunction($function, $name, $file->getPathname(), $imports);
				}
			}
			if (!$typesOnly && $spaces !== []) {
				$this->namespaces[CallGraphNames::commonNamespace($spaces)] = $name;
			}
		}
	}

	/**
	 * @param string $class FQCN
	 * @return bool whether its bodies are followed (a class of a followed tree)
	 */
	public function followed(string $class): bool {
		return isset($this->classes[$class]) && !$this->classes[$class]['outside'];
	}

	/**
	 * @param string $key a unit key
	 * @return array{node:Stmt\ClassMethod|Stmt\Function_, file:string, path:string, class:?string, params:array<string,string>, returns:?string}
	 */
	public function unit(string $key): array {
		if (isset($this->functionUnits[$key])) {
			return $this->functionUnits[$key] + ['class' => null];
		}
		[$class, $method] = explode('::', $key);
		return $this->classes[$class]['methods'][strtolower($method)] + ['class' => $class, 'file' => $this->classes[$class]['file'], 'path' => $this->classes[$class]['path']];
	}

	/**
	 * @param string $class a class
	 * @param string $method a case-insensitive method name
	 * @return string the canonical declaration name when indexed, otherwise the supplied name
	 */
	public function methodName(string $class, string $method): string {
		if (isset($this->classes[$class]['methods'][strtolower($method)])) {
			return $this->classes[$class]['methods'][strtolower($method)]['node']->name->name;
		}
		if (class_exists($class, false)) {
			$reflection = new ReflectionClass($class);
			if ($reflection->isInternal() && $reflection->hasMethod($method)) {
				return $reflection->getMethod($method)->getName();
			}
		}
		return $method;
	}

	/**
	 * @param string $fqcn a class
	 * @return list<string> every class and interface it extends or implements, followed or outside
	 */
	public function ancestors(string $fqcn): array {
		$found = [];
		$todo = [$fqcn];
		while ($todo !== []) {
			foreach ($this->classes[array_shift($todo)]['parents'] ?? [] as $parent) {
				if (!in_array($parent, $found, true)) {
					$found[] = $parent;
					$todo[] = $parent;
				}
			}
		}
		return $found;
	}

	/**
	 * @param Node\Name $name PHP's resolved or namespace-relative function name
	 * @return string the indexed namespaced definition before PHP's global builtin fallback
	 */
	public function functionName(Node\Name $name): string {
		$local = $name->getAttribute('namespacedName')?->toString();
		foreach (array_filter([$local, $name->toString()]) as $candidate) {
			foreach (array_keys($this->functions) as $declared) {
				if (strcasecmp($candidate, $declared) === 0) {
					return $declared;
				}
			}
		}
		return $name->toString();
	}

	/**
	 * @param string $class FQCN
	 * @return array<string, string> the imports of the file it was read from
	 */
	public function imports(string $class): array {
		return $this->imports[$class] ?? [];
	}

	/**
	 * @param string $class FQCN
	 * @return array<string, string> its stated property types, inherited ones included
	 */
	public function props(string $class): array {
		$props = [];
		foreach ([$class, ...$this->ancestors($class)] as $c) {
			$props += $this->classes[$c]['props'] ?? [];
		}
		return $props;
	}

	/**
	 * @param string $class FQCN
	 * @param string $name constant name
	 * @return Expr|null the value written for it, in the class or a parent
	 */
	public function constant(string $class, string $name): ?Expr {
		foreach ([$class, ...$this->ancestors($class)] as $c) {
			if (isset($this->classes[$c]['consts'][$name])) {
				return $this->classes[$c]['consts'][$name];
			}
		}
		return null;
	}

	/**
	 * @param string|null $class FQCN of the code, null in a function
	 * @param Node\Name $name a resolved class name in it
	 * @return string the FQCN it means, self/static/parent included
	 */
	public function name(?string $class, Node\Name $name): string {
		return match (strtolower($name->toString())) {
			'self', 'static' => (string)$class,
			'parent' => (string)($this->classes[(string)$class]['extends'] ?? ''),
			default => $name->toString(),
		};
	}

	/**
	 * @param Stmt\Function_ $node a global function of a followed tree
	 * @param string $file its path as reported
	 * @param string $path its path on disk
	 * @param array<string, string> $imports the imports of its file
	 * @return void
	 */
	private function indexFunction(Stmt\Function_ $node, string $file, string $path, array $imports): void {
		$name = $node->namespacedName?->toString() ?? $node->name->name;
		$namespace = substr($name, 0, (int)strrpos('\\' . $name, '\\'));
		[$returns, $params] = $this->signatures->signature($node, static fn (string $t): ?string => CallGraphNames::docType($imports, $namespace, null, $t), null);
		$key = 'function ' . $name . '@' . $file;
		$this->functionUnits[$key] = ['node' => $node, 'file' => $file, 'path' => $path, 'params' => $params, 'returns' => $returns];
		$this->functions[$name][] = $key;
	}

	/**
	 * @param Stmt\ClassLike $node a class, interface or trait
	 * @param string $file its path as reported
	 * @param string $path its path on disk
	 * @param bool $typesOnly whether it belongs to the type-only tree
	 * @return void
	 */
	private function index(Stmt\ClassLike $node, string $file, string $path, bool $typesOnly): void {
		$fqcn = $node->namespacedName->toString();
		$namespace = substr($fqcn, 0, (int)strrpos($fqcn, '\\') + 1);
		$doc = fn (string $t): ?string => CallGraphNames::docType($this->imports[$fqcn], $namespace, $fqcn, $t);
		$extends = $node instanceof Stmt\Class_ ? $node->extends?->toString() : null;
		$parents = match (true) {
			$node instanceof Stmt\Class_ => array_values(array_filter([$extends, ...array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements)])),
			$node instanceof Stmt\Interface_ => array_map(static fn (Node\Name $n): string => $n->toString(), $node->extends),
			default => [],
		};
		$props = [];
		if ($node instanceof Stmt\Enum_) {
			$props = $node->scalarType === null ? ['name' => ''] : ['name' => '', 'value' => ''];
		}
		foreach ($node->getProperties() as $property) {
			$text = $property->getDocComment()?->getText() ?? '';
			$type = $this->signatures->declared($property->type, $fqcn) ?? (preg_match('/@var\s+(\S+(?:\s+[^>\r\n]*>)?)/', $text, $m) ? $doc($m[1]) : null);
			foreach ($property->props as $prop) {
				if (($known = $type ?? CallGraphLiterals::type($prop->default)) !== null) {
					$props[$prop->name->name] = $known;
				}
			}
		}
		// Magic getter contracts require declarations, never inferred property defaults.
		$declaredProps = [];
		foreach ($node->getProperties() as $property) {
			$text = $property->getDocComment()?->getText() ?? '';
			$type = $this->signatures->declared($property->type, $fqcn)
				?? (preg_match('/@var\s+(\S+)/', $text, $m) ? $doc($m[1]) : null);
			foreach ($property->props as $prop) {
				$declaredProps[$prop->name->name] = $type;
			}
		}
		$magicReturns = [];
		preg_match_all('/@method\s+(?:static\s+)?(\S+)\s+(\w+)\s*\(/', $node->getDocComment()?->getText() ?? '', $matches, PREG_SET_ORDER);
		foreach ($matches as [, $type, $method]) {
			$magicReturns[strtolower($method)] = $doc($type);
		}
		$consts = [];
		foreach ($node->getConstants() as $group) {
			foreach ($group->consts as $const) {
				$consts[$const->name->name] = $const->value;
			}
		}
		$methods = [];
		foreach ($node->getMethods() as $method) {
			[$returns, $params] = $this->signatures->signature($method, $doc, $fqcn);
			foreach ($method->params as $param) {
				// A promoted parameter is a typed property.
				if ($param->flags !== 0 && is_string($param->var->name) && isset($params[$param->var->name])) {
					$props[$param->var->name] = $params[$param->var->name];
					$declaredProps[$param->var->name] = $params[$param->var->name];
				}
			}
			// An untyped property the constructor sets from a typed parameter has that parameter's type.
			foreach ($method->name->toLowerString() === '__construct' ? (new NodeFinder())->findInstanceOf($method->stmts ?? [], Expr\Assign::class) : [] as $set) {
				if ($set->var instanceof Expr\PropertyFetch && $set->var->var instanceof Expr\Variable && $set->var->var->name === 'this'
					&& $set->var->name instanceof Node\Identifier && $set->expr instanceof Expr\Variable && isset($params[(string)$set->expr->name])) {
					$props[$set->var->name->name] ??= $params[(string)$set->expr->name];
				}
			}
			foreach ($method->name->toLowerString() === '__construct' ? (new NodeFinder())->findInstanceOf($method->stmts ?? [], Stmt\Expression::class) : [] as $statement) {
				$set = $statement->expr;
				if ($set instanceof Expr\Assign && $set->var instanceof Expr\PropertyFetch && $set->var->var instanceof Expr\Variable
					&& $set->var->var->name === 'this' && $set->var->name instanceof Node\Identifier && $set->expr instanceof Expr\Variable
					&& preg_match('/@var\s+(\S+)\s+\$' . preg_quote((string)$set->expr->name, '/') . '\b/', $statement->getDocComment()?->getText() ?? '', $match)
					&& ($type = $doc($match[1])) !== null) {
					$props[$set->var->name->name] ??= $type;
				}
			}
			$methods[$method->name->toLowerString()] = ['node' => $method, 'returns' => $returns, 'params' => $params];
		}
		$traits = [];
		foreach ($node->getTraitUses() as $use) {
			array_push($traits, ...array_map(static fn (Node\Name $n): string => $n->toString(), $use->traits));
		}
		$this->classes[$fqcn] = ['outside' => $typesOnly, 'file' => $file, 'path' => $path, 'extends' => $extends, 'parents' => $parents, 'traits' => $traits,
			'doc' => $node->getDocComment()?->getText() ?? '', 'declaredProps' => $declaredProps, 'magicReturns' => $magicReturns, 'props' => $props, 'consts' => $consts, 'methods' => $methods];
	}
}
