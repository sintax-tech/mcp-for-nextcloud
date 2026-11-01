<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Construction;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/** Detects constructor and actual factory cycles with the complete active path. */
final class ConstructionGraph {
	/** @param list<class-string> $classes Concrete app classes. @return array<string,list<string>> Required constructor dependencies. */
	public static function constructors(array $classes): array {
		$graph = [];
		foreach ($classes as $class) {
			$graph[$class] = [];
			foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
				$type = $parameter->getType();
				if (!$parameter->isOptional() && $type instanceof ReflectionNamedType && !$type->isBuiltin() && str_starts_with($type->getName(), 'OCA\\Mcp\\')) { $graph[$class][] = $type->getName(); }
			}
		}
		return $graph;
	}

	/** @param array<string,list<string>> $graph Immediate dependencies only. @return void @throws LogicException A cycle exists. */
	public static function assertAcyclic(array $graph): void {
		$colors = []; $path = [];
		$visit = static function (string $node) use (&$visit, &$colors, &$path, $graph): void {
			if (($colors[$node] ?? 0) === 1) { throw new LogicException('DI cycle: ' . implode(' -> ', [...$path, $node])); }
			if (($colors[$node] ?? 0) === 2) { return; }
			$colors[$node] = 1; $path[] = $node;
			foreach ($graph[$node] ?? [] as $target) { $visit($target); }
			array_pop($path); $colors[$node] = 2;
		};
		foreach (array_keys($graph) as $node) { $visit($node); }
	}
}
