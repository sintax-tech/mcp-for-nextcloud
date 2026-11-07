<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Where a call can land. A method call reaches the method the receiver's class runs (its own or inherited body, or a
 * leaf on the first class outside the followed trees) and, since the object may be of a subclass, every override in
 * a followed class extending or implementing the receiver's type. A function reaches every followed definition of
 * it, or is a leaf. A class of a followed tree that the tree does not have, and a method declared nowhere, are unknown.
 */
final class CallGraphDispatch {
	/** Methods PHP runs on its own when a class declares them, so their triggers are refused. */
	public const MAGIC = ['__get', '__set', '__isset', '__unset', '__clone', '__invoke', '__toString', '__call', '__callStatic'];

	/** @var array<string, list<string>> by magic method, the followed classes that declare it and every type they are */
	private array $magic = [];

	/** @param CallGraphIndex $index the parsed trees */
	public function __construct(private CallGraphIndex $index) {
		foreach ($index->classes as $fqcn => $info) {
			foreach ($info['outside'] ? [] : self::MAGIC as $method) {
				$holders = [$fqcn, ...$index->ancestors($fqcn), ...$info['traits']];
				if (array_filter($holders, fn (string $c): bool => isset($index->classes[$c]['methods'][$method])) === []) {
					continue;
				}
				$this->magic[$method] = array_values(array_unique([...($this->magic[$method] ?? []), $fqcn, ...$index->ancestors($fqcn)]));
			}
		}
	}

	/**
	 * @param string $method a magic method
	 * @return list<string> the types a value may have for PHP to run it: a followed class declaring it, or any of its types
	 */
	public function magic(string $method): array {
		return $this->magic[$method] ?? [];
	}

	/**
	 * @param string $call `Class::method` or `function name`
	 * @param list<string> $unknown collects magic calls and missing classes
	 * @return list<string> unit keys of followed bodies, or leaves
	 */
	public function targets(string $call, array &$unknown): array {
		if (str_starts_with($call, 'function ')) {
			return $this->index->functions[substr($call, strlen('function '))] ?? [$call];
		}
		[$class, $method] = explode('::', $call);
		$targets = $this->landing($class, $method, $unknown);
		if ($method === '__construct') {
			return $targets;
		}
		foreach ($this->index->classes as $fqcn => $info) {
			if ($info['outside'] || $fqcn === $class || !in_array($class, $this->index->ancestors($fqcn), true)) {
				continue;
			}
			foreach ([$fqcn, ...$info['traits']] as $holder) {
				if (($this->index->classes[$holder]['methods'][$method]['node'] ?? null)?->stmts !== null) {
					$targets[] = $holder . '::' . $method;
				}
			}
		}
		return array_values(array_unique($targets));
	}

	/**
	 * @param string $key a unit key or a leaf
	 * @return bool whether it is a followed body
	 */
	public function isUnit(string $key): bool {
		return str_starts_with($key, 'function ') ? str_contains($key, '@') : $this->index->followed(explode('::', $key)[0]);
	}

	/**
	 * @param string $class FQCN of the receiver
	 * @param string $method method name
	 * @param list<string> $unknown collects magic calls and missing classes
	 * @return list<string> the method the class itself runs: its own or inherited body, or a leaf outside the followed trees
	 */
	private function landing(string $class, string $method, array &$unknown): array {
		if (!$this->index->followed($class)) {
			foreach (isset($this->index->classes[$class]) ? [] : $this->index->namespaces as $namespace => $file) {
				if (str_starts_with($class, $namespace)) {
					$unknown[] = $file . ':0 ' . $class . '::' . $method . ' names a class of a followed tree the tree does not have';
					return [];
				}
			}
			return [$this->outsideOwner($class, $method) . '::' . $method];
		}
		for ($c = $class; $c !== null && $this->index->followed($c); $c = $this->index->classes[$c]['extends']) {
			foreach ([$c, ...$this->index->classes[$c]['traits']] as $holder) {
				if (($this->index->classes[$holder]['methods'][$method]['node'] ?? null)?->stmts !== null) {
					return [$holder . '::' . $method];
				}
			}
		}
		for ($c = $class; $c !== null; $c = $this->index->classes[$c]['extends'] ?? null) {
			if (!$this->index->followed($c)) {
				return [$this->outsideOwner($c, $method) . '::' . $method];
			}
		}
		// An interface or abstract method: its implementations are added as overrides.
		if (isset($this->index->classes[$class]['methods'][$method]) || $method === '__construct') {
			return [];
		}
		$unknown[] = $this->index->classes[$class]['file'] . ':0 ' . $class . '::' . $method . ' is declared nowhere the reader can see (magic __call)';
		return [];
	}

	/**
	 * @param string $class an outside class
	 * @param string $method method name
	 * @return string the outside class or interface that declares it, from the type-only tree; the class itself otherwise
	 */
	public function outsideOwner(string $class, string $method): string {
		foreach ([$class, ...$this->index->ancestors($class)] as $c) {
			if (isset($this->index->classes[$c]['methods'][$method])) {
				return $c;
			}
		}
		return $class;
	}
}
