<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Every Collectives method an entry point can reach, read from a source tree without running it. Calls are followed
 * through `$this->property->method(` (typed and promoted properties), `$this->method(`, `self::`/`static::`/`parent::`
 * and `Class::method(` inside OCA\Collectives, and through local variables whose type the source states: a typed
 * parameter, `new X`, `X::class`, or the declared return type of a resolved call. A value returned by a call that leaves
 * the app is outside it. A variable whose type cannot be read (a foreach item, say) makes every Collectives method of
 * that name reachable: the set over-approximates, never misses. Calls leaving the app are kept as named leaves.
 */
final class CollectivesCallGraph {
	/** Type of a value that came from outside the app. */
	private const OUTSIDE = '(outside)';

	/** @var array<string, array{extends:?string, properties:array<string,string>, methods:array<string,string>, returns:array<string,string>, params:array<string,array<string,string>>}> classes by FQCN */
	private array $classes = [];

	/** @param string $lib the app's lib/ directory */
	public function __construct(string $lib) {
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, FilesystemIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() === 'php') {
				$this->index((string)file_get_contents($file->getPathname()));
			}
		}
	}

	/**
	 * @param list<string> $entries `FQCN::method` entry points
	 * @return array{methods:array<string,string>, leaves:list<string>, via:array<string,string>} the body of every reachable
	 *   method, the calls leaving the app and, for each method, the caller it was first reached from
	 */
	public function reach(array $entries): array {
		$methods = [];
		$leaves = [];
		$via = [];
		$queue = array_map(static fn (string $entry): array => [$entry, 'entry'], $entries);
		while ($queue !== []) {
			[$ref, $from] = array_shift($queue);
			if (isset($methods[$ref])) {
				continue;
			}
			[$class, $method] = explode('::', $ref);
			$owner = $this->owner($class, $method);
			if ($owner === null) {
				$leaves[] = $ref;
				continue;
			}
			$body = $this->classes[$owner]['methods'][$method];
			$methods[$owner . '::' . $method] = $body;
			$via[$owner . '::' . $method] ??= $from;
			foreach ($this->calls($owner, $body) as $call) {
				$queue[] = [$call, $owner . '::' . $method];
			}
		}
		ksort($methods);
		ksort($via);
		$leaves = array_values(array_unique($leaves));
		sort($leaves);
		return ['methods' => $methods, 'leaves' => $leaves, 'via' => $via];
	}

	/**
	 * @param string $class FQCN
	 * @param string $method method name
	 * @return string|null the Collectives class that declares it, walking up extends; null outside the app
	 */
	private function owner(string $class, string $method): ?string {
		while ($class !== null && isset($this->classes[$class])) {
			if (isset($this->classes[$class]['methods'][$method])) {
				return $class;
			}
			$class = $this->classes[$class]['extends'];
		}
		return null;
	}

	/**
	 * @param string $class FQCN of the body's class
	 * @param string $body method source
	 * @return list<string> `FQCN::method` of every call the body makes, over-approximated for untyped receivers
	 */
	private function calls(string $class, string $body): array {
		$calls = [];
		$properties = $this->properties($class);
		preg_match_all('/\$this->(\w+)->(\w+)\(/', $body, $m, PREG_SET_ORDER);
		foreach ($m as [, $property, $method]) {
			$calls[] = ($properties[$property] ?? '?') . '::' . $method;
		}
		preg_match_all('/\$this->(\w+)\(/', $body, $m, PREG_SET_ORDER);
		foreach ($m as [, $method]) {
			$calls[] = $class . '::' . $method;
		}
		preg_match_all('/\b(self|static|parent|[A-Z]\w*)::(\w+)\(/', $body, $m, PREG_SET_ORDER);
		foreach ($m as [, $target, $method]) {
			$calls[] = match ($target) {
				'self', 'static' => $class,
				'parent' => (string)$this->classes[$class]['extends'],
				default => $this->imports[$class][$target] ?? substr($class, 0, (int)strrpos($class, '\\') + 1) . $target,
			} . '::' . $method;
		}
		$locals = $this->locals($class, $body);
		preg_match_all('/\$(?!this\b)(\w+)->(\w+)\(/', $body, $m, PREG_SET_ORDER);
		foreach ($m as [, $variable, $method]) {
			$type = $locals[$variable] ?? null;
			if ($type === self::OUTSIDE) {
				$calls[] = '(outside value)::' . $method;
			} elseif ($type !== null) {
				$calls[] = $type . '::' . $method;
			} else {
				foreach ($this->classes as $fqcn => $info) {
					if (isset($info['methods'][$method])) {
						$calls[] = $fqcn . '::' . $method;
					}
				}
				$calls[] = '(untyped value)::' . $method;
			}
		}
		return array_values(array_unique($calls));
	}

	/**
	 * The types of the local variables of a body that the source states.
	 * @param string $class FQCN of the body's class
	 * @param string $body method source
	 * @return array<string, string> variable name to FQCN, or OUTSIDE for a value from outside the app
	 */
	private function locals(string $class, string $body): array {
		$imports = $this->imports[$class] ?? [];
		$namespace = substr($class, 0, (int)strrpos($class, '\\') + 1);
		$resolve = fn (string $type): string => str_contains($type, '\\') ? ltrim($type, '\\')
			: ($imports[$type] ?? (in_array(strtolower($type), ['self', 'static'], true) ? $class : $namespace . $type));
		$types = [];
		if (preg_match('/function\s+\w+\s*\((.*?)\)\s*(?::|\{)/s', $body, $signature)) {
			preg_match_all('/\??([A-Z][\w\\\\]*)\s+\$(\w+)/', $signature[1], $params, PREG_SET_ORDER);
			foreach ($params as [, $type, $name]) {
				$types[$name] = $this->inside($resolve($type));
			}
		}
		$properties = $this->properties($class);
		preg_match_all('/\$(\w+)\s*=\s*(?:\(\w+\)\s*)?(new\s+([\w\\\\]+)|\$this->(\w+)->(\w+)\(|\$this->(\w+)\(|([A-Z][\w\\\\]*)::(\w+)\((?:([\w\\\\]+)::class)?)/', $body, $assign, PREG_SET_ORDER);
		foreach ($assign as $a) {
			$name = $a[1];
			if (!empty($a[3])) {
				$types[$name] = $this->inside($resolve($a[3]));
			} elseif (!empty($a[4])) {
				$owner = $properties[$a[4]] ?? null;
				$types[$name] = $owner === null || !isset($this->classes[$owner]) ? self::OUTSIDE : ($this->returnOf($owner, $a[5]) ?? self::OUTSIDE);
			} elseif (!empty($a[6])) {
				$types[$name] = $this->returnOf($class, $a[6]) ?? self::OUTSIDE;
			} elseif (!empty($a[7])) {
				$target = $resolve($a[7]);
				$named = !empty($a[9]) ? $this->inside($resolve($a[9])) : self::OUTSIDE;
				$types[$name] = isset($this->classes[$target]) ? ($this->returnOf($target, $a[8]) ?? self::OUTSIDE) : $named;
			}
		}
		return $types;
	}

	/**
	 * @param string $type FQCN
	 * @return string the FQCN when it is a class of the app, OUTSIDE otherwise
	 */
	private function inside(string $type): string {
		return isset($this->classes[$type]) ? $type : self::OUTSIDE;
	}

	/**
	 * @param string $class FQCN
	 * @param string $method method name
	 * @return string|null the declared return type, OUTSIDE when it is not a class of the app, null when undeclared
	 */
	private function returnOf(string $class, string $method): ?string {
		$owner = $this->owner($class, $method);
		$type = $owner === null ? null : ($this->classes[$owner]['returns'][$method] ?? null);
		return $type === null ? null : $this->inside($type);
	}

	/**
	 * @param string $class FQCN
	 * @return array<string, string> property name to FQCN, inherited ones included
	 */
	private function properties(string $class): array {
		$properties = [];
		for ($c = $class; $c !== null && isset($this->classes[$c]); $c = $this->classes[$c]['extends']) {
			$properties += $this->classes[$c]['properties'];
		}
		return $properties;
	}

	/** @var array<string, array<string, string>> short name to FQCN, per class */
	private array $imports = [];

	/** @param string $source a PHP file of the app */
	private function index(string $source): void {
		if (!preg_match('/^namespace\s+([^;]+);/m', $source, $ns) || !preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)(?:\s+extends\s+([\w\\\\]+))?/m', $source, $decl)) {
			return;
		}
		$fqcn = $ns[1] . '\\' . $decl[1];
		$imports = [];
		preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $uses, PREG_SET_ORDER);
		foreach ($uses as $use) {
			$imports[$use[2] ?? substr(strrchr('\\' . $use[1], '\\'), 1)] = $use[1];
		}
		$resolve = static fn (string $type): string => str_contains($type, '\\') ? ltrim($type, '\\') : ($imports[$type] ?? $ns[1] . '\\' . $type);
		$properties = [];
		preg_match_all('/(?:private|protected|public)\s+(?:readonly\s+)?\??([\w\\\\]+)\s+\$(\w+)/', $source, $props, PREG_SET_ORDER);
		foreach ($props as [, $type, $name]) {
			$properties[$name] = $resolve($type);
		}
		$methods = [];
		$returns = [];
		preg_match_all('/function\s+(\w+)\s*\([^{;]*?\)\s*:\s*\??([\w\\\\]+)/s', $source, $signatures, PREG_SET_ORDER);
		foreach ($signatures as [, $name, $type]) {
			$returns[$name] = in_array(strtolower($type), ['self', 'static'], true) ? $fqcn : $resolve($type);
		}
		preg_match_all('/function\s+(\w+)\s*\(/', $source, $names);
		foreach (array_unique($names[1]) as $name) {
			$body = self::body($source, $name);
			if ($body !== null) {
				$methods[$name] = $body;
			}
		}
		$this->imports[$fqcn] = $imports;
		$this->classes[$fqcn] = ['extends' => isset($decl[2]) ? $resolve($decl[2]) : null, 'properties' => $properties, 'methods' => $methods,
			'returns' => $returns, 'params' => []];
	}

	/**
	 * One method copied verbatim from `function` to its closing brace; null for an abstract or interface method.
	 * @param string $source PHP source
	 * @param string $name method name
	 * @return string|null the method
	 */
	public static function body(string $source, string $name): ?string {
		$tokens = token_get_all($source);
		foreach ($tokens as $i => $token) {
			if (!is_array($token) || $token[0] !== T_FUNCTION) {
				continue;
			}
			$j = $i + 1;
			while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}
			if (!is_array($tokens[$j] ?? null) || $tokens[$j][1] !== $name) {
				continue;
			}
			$text = '';
			$depth = 0;
			for ($k = $i; $k < count($tokens); $k++) {
				$text .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
				if ($depth === 0 && $tokens[$k] === ';') {
					return null;
				}
				if ($tokens[$k] === '{' || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
					$depth++;
				} elseif ($tokens[$k] === '}' && --$depth === 0) {
					return $text;
				}
			}
		}
		return null;
	}
}
