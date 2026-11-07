<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Loose object comparison compares stored instance properties recursively, including their string conversion hooks. */
final class CallGraphComparisons {
	/**
	 * @param CallGraphIndex $index declarations
	 * @param CallGraphImplicitEdges $edges shared dispatch
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphImplicitEdges $edges) {
	}

	/**
	 * @param list<?string> $types compared value alternatives
	 * @param Node $node comparison site
	 * @param string $caller canonical unit
	 * @param callable(Node,string):void $miss source-positioned unknown
	 * @param list<string> $seen recursion guard for cyclic object graphs
	 * @return list<string> all conversion bodies of the object graph
	 */
	public function of(array $types, Node $node, string $caller, callable $miss, array $seen = []): array {
		CallGraphPropertyHooks::check($types, null, $node, $this->index, new CallGraphDispatch($this->index), $miss);
		$edges = $this->edges->of($types, ['__toString'], $node, $caller, $miss);
		foreach (CallGraphArrayTypes::leaves($types) as $class) {
			if (!CallGraphTypes::isObject($class) || in_array($class, $seen, true)) {
				continue;
			}
			$declaration = $this->index->classes[$class]['node'] ?? null;
			if ($declaration === null) {
				$miss($node, 'compared object state has no pinned declaration: ' . $class);
				continue;
			}
			foreach ([$class, ...$this->index->ancestors($class)] as $owner) {
				$info = $this->index->classes[$owner] ?? [];
				$fields = [];
				foreach (($info['node'] ?? null)?->getProperties() ?? [] as $property) {
					foreach ($property->isStatic() ? [] : $property->props as $prop) {
						$fields[] = $prop->name->name;
					}
				}
				foreach (($info['node'] ?? null)?->getMethods() ?? [] as $method) {
					foreach ($method->params as $param) {
						if ($param->flags !== 0) {
							$fields[] = $param->var->name;
						}
					}
				}
				foreach (array_unique($fields) as $field) {
					$type = $this->index->props($class)[$field] ?? null;
					array_push($edges, ...$this->of([$type], $node, $caller, $miss, [...$seen, $class]));
				}
			}
		}
		return array_values(array_unique($edges));
	}
}
