<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Case-insensitive dispatch through app bodies, pinned ancestors and PHP's internal methods. */
final class CallGraphDispatch {
	/** PHP hooks whose pinned declaring classes must be followed rather than treated as opaque leaves. */
	private const MAGIC = ['__get', '__set', '__isset', '__unset', '__call', '__callstatic', '__clone', '__invoke', '__tostring',
		'__serialize', '__unserialize', '__set_state', '__debuginfo', '__sleep', '__wakeup', '__destruct'];

	/** @param CallGraphIndex $index the parsed, pinned source trees */
	public function __construct(private CallGraphIndex $index) {
	}

	/**
	 * @param string $class the static type of an instance
	 * @return list<string> that class and every followed subclass or implementation
	 */
	public function instances(string $class): array {
		$found = [$class];
		foreach ($this->index->classes as $fqcn => $info) {
			if (!$info['outside'] && in_array($class, $this->index->ancestors($fqcn), true)) {
				$found[] = $fqcn;
			}
		}
		return array_values(array_unique($found));
	}

	/**
	 * @param string $class a receiver class
	 * @param string $method a case-insensitive name
	 * @return string|null the indexed declaring class, including inherited traits
	 */
	public function owner(string $class, string $method): ?string {
		$todo = [$class];
		$seen = [];
		while ($todo !== []) {
			$c = array_shift($todo);
			if (isset($seen[$c])) {
				continue;
			}
			$seen[$c] = true;
			if (isset($this->index->classes[$c]['methods'][strtolower($method)])) {
				return $c;
			}
			$info = $this->index->classes[$c] ?? [];
			array_push($todo, ...($info['traits'] ?? []), ...($info['parents'] ?? []));
		}
		return null;
	}

	/**
	 * @param string $call `Class::method`, `static Class::method`, `php-core Class::method` or `function name`
	 * @param list<string> $unknown unresolved destinations
	 * @return list<string> canonical body keys or leaves, including every possible override
	 */
	public function targets(string $call, array &$unknown): array {
		if (str_starts_with($call, 'function ')) {
			return $this->index->functions[substr($call, strlen('function '))] ?? [$call];
		}
		if (str_starts_with($call, 'php-core ')) {
			return [$call];
		}
		$static = str_starts_with($call, 'static ');
		[$class, $method] = explode('::', $static ? substr($call, 7) : $call);
		$targets = $this->landing($class, $method, $static, $unknown);
		if (strtolower($method) !== '__construct') {
			foreach (array_slice($this->instances($class), 1) as $subclass) {
				array_push($targets, ...$this->landing($subclass, $method, $static, $unknown));
			}
		}
		return array_values(array_unique($targets));
	}

	/**
	 * @param string $key a canonical destination
	 * @return bool whether its body is available and is part of the followed app or a pinned magic declaring class
	 */
	public function isUnit(string $key): bool {
		if (str_starts_with($key, 'function ')) {
			return str_contains($key, '@');
		}
		if (str_starts_with($key, 'php-core ')) {
			return false;
		}
		[$class, $method] = explode('::', $key);
		$info = $this->index->classes[$class] ?? [];
		return ($info['methods'][strtolower($method)]['node'] ?? null)?->stmts !== null
			&& ($this->index->followed($class) || array_intersect(self::MAGIC, array_keys($info['methods'] ?? [])) !== []);
	}

	/**
	 * @param string $class a possible receiver
	 * @param string $hook the implicit hook being triggered
	 * @param list<string> $unknown unresolved implicit destinations
	 * @return list<string> its body or a PHP-core leaf; no edge when the complete known ancestry has no hook
	 */
	public function implicit(string $class, string $hook, array &$unknown): array {
		if (($owner = $this->owner($class, $hook)) !== null) {
			$key = $owner . '::' . $this->index->methodName($owner, $hook);
			if ($this->isUnit($key)) {
				return [$key];
			}
			$unknown[] = ($this->index->classes[$class]['file'] ?? $class) . ':0 implicit ' . $key . ' has no pinned body';
			return [];
		}
		foreach ([$class, ...$this->index->ancestors($class)] as $ancestor) {
			if ((class_exists($ancestor, false) || interface_exists($ancestor, false)) && ($reflection = new ReflectionClass($ancestor))->isInternal() && $reflection->hasMethod($hook)) {
				$method = $reflection->getMethod($hook);
				return ['php-core ' . $method->getDeclaringClass()->getName() . '::' . $method->getName()];
			}
		}
		$todo = [$class];
		$seen = [];
		while ($todo !== []) {
			$ancestor = array_pop($todo);
			if (isset($seen[$ancestor])) {
				continue;
			}
			$seen[$ancestor] = true;
			if (isset($this->index->classes[$ancestor])) {
				$info = $this->index->classes[$ancestor];
				array_push($todo, ...$info['traits'], ...array_filter([$info['extends']]));
			} elseif (!(class_exists($ancestor, false) && (new ReflectionClass($ancestor))->isInternal())) {
				$unknown[] = ($this->index->classes[$class]['file'] ?? $class) . ':0 implicit ' . $class . '::' . $hook . ' has an unpinned ancestor';
			}
		}
		return [];
	}

	/**
	 * @param string $class receiver
	 * @param string $method method as written
	 * @param bool $static whether the syntax is a static call
	 * @param list<string> $unknown unresolved destinations
	 * @return list<string> its declaration, magic fallback or explicit refusal
	 */
	private function landing(string $class, string $method, bool $static, array &$unknown): array {
		if (($owner = $this->owner($class, $method)) !== null) {
			if ($this->index->followed($owner) && $this->index->classes[$owner]['methods'][strtolower($method)]['node']->stmts === null) {
				return [];
			}
			return [$owner . '::' . $this->index->methodName($owner, $method)];
		}
		foreach ([$class, ...$this->index->ancestors($class)] as $ancestor) {
			if ((class_exists($ancestor, false) || interface_exists($ancestor, false)) && ($reflection = new ReflectionClass($ancestor))->isInternal() && $reflection->hasMethod($method)) {
				$m = $reflection->getMethod($method);
				return [$m->getDeclaringClass()->getName() . '::' . $m->getName()];
			}
		}
		if (strtolower($method) !== '__construct' && $this->owner($class, $static ? '__callStatic' : '__call') !== null) {
			return $this->implicit($class, $static ? '__callStatic' : '__call', $unknown);
		}
		if ($this->index->followed($class)) {
			if (strtolower($method) === '__construct') {
				return [];
			}
			$unknown[] = $this->index->classes[$class]['file'] . ':0 ' . $class . '::' . $method . ' is declared nowhere the reader can see (magic __call)';
			return [];
		}
		foreach (isset($this->index->classes[$class]) ? [] : $this->index->namespaces as $namespace => $file) {
			if (str_starts_with($class, $namespace)) {
				$unknown[] = $file . ':0 ' . $class . '::' . $method . ' names a class of a followed tree the tree does not have';
				return [];
			}
		}
		return [$class . '::' . $method];
	}
}
