<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/** Class-wide array writes refine field values only from declarations, initializers and every syntactically possible writer. */
final class CallGraphPropertyFacts {
	/** @var array<string,?string> field estimates and round-local recursion guards */
	private array $values = [], $active = [], $done = [];

	/**
	 * @param CallGraphIndex $index declarations
	 * @param CallGraphTypes $types local/return proof
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphTypes $types) {
	}
	/** @return void independent query */
	public function clear(): void { $this->values = $this->active = $this->done = []; }
	/** @return void new fixed-point round */
	public function begin(): void { $this->active = $this->done = []; }
	/** @return string convergence evidence */
	public function snapshot(): string { $values = $this->values; ksort($values); return serialize($values); }

	/**
	 * @param string $class receiver class
	 * @param string $field exact property name
	 * @return string|null declared field type, or a joined array value proof covering its initialization and all writers
	 */
	public function of(string $class, string $field): ?string {
		$base = $this->index->props($class)[$field] ?? null;
		if (CallGraphArrayTypes::read($base) === null) { return $base; }
		$key = $class . '::$' . $field;
		if (isset($this->active[$key])) { return $this->values[$key] ?? $base; }
		if (isset($this->done[$key])) { return $this->values[$key]; }
		$this->active[$key] = true;
		try {
			$owner = null;
			$initial = null;
			$nativeArray = false;
			foreach ([$class, ...$this->index->ancestors($class)] as $candidate) {
				foreach (($this->index->classes[$candidate]['node'] ?? null)?->getProperties() ?? [] as $property) {
					foreach ($property->props as $prop) {
						if ($prop->name->name === $field) { $owner = $candidate; $initial = $prop->default; $nativeArray = $property->type instanceof Node\Identifier && $property->type->toLowerString() === 'array'; break 3; }
					}
				}
			}
			if ($owner === null || $initial === null) { return $this->values[$key] = $base; }
			$values = $this->types->typeOf($owner, $initial, []);
			foreach ([$class, ...$this->index->ancestors($class)] as $scope) {
				foreach ($this->index->classes[$scope]['methods'] ?? [] as $method) {
					$body = $scope . '::' . $method['node']->name->name;
					$locals = null;
					foreach ((new NodeFinder())->findInstanceOf($method['node']->stmts ?? [], Expr\Assign::class) as $assign) {
						[$root, $path] = CallGraphArrayWrites::path($assign->var);
						if (!($root instanceof Expr\PropertyFetch && $root->var instanceof Expr\Variable && $root->var->name === 'this'
							|| $root instanceof Expr\StaticPropertyFetch)) { continue; }
						if (!$root->name instanceof Node\Identifier || $root->name->name !== $field) { continue; }
						$locals ??= $this->types->locals->of($body, true);
						$assigned = $this->types->typeOf($scope, $assign->expr, $locals);
						if ($path !== []) { $assigned = CallGraphArrayWrites::write($values, $path, $assigned); }
						if ($nativeArray) {
							$assigned = array_map(static fn (?string $t): ?string => in_array($t, ['json-value', 'json-object-value'], true) ? CallGraphArrayTypes::make([''], [$t]) : $t, $assigned);
							$assigned = array_values(array_filter($assigned, static fn (?string $t): bool => $t === null || $t === '@bottom' || CallGraphArrayTypes::read($t) !== null));
						}
						$values = CallGraphTypes::union([...$values, ...$assigned]);
					}
				}
			}
			$this->done[$key] = true;
			return $this->values[$key] = CallGraphArrayTypes::pack($values);
		} finally { unset($this->active[$key]); }
	}
}
