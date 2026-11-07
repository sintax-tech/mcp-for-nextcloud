<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Audited construction wiring identifies the result implementations of the four supported database drivers. */
final class CollectivesSqlWiring {
	/**
	 * @param CallGraphIndex $index SHA-pinned Doctrine files
	 * @param array<string,string> $drivers configured driver names and expected result classes
	 * @return array<string,string> construction evidence, separate from fetch bodies (no query is executed)
	 */
	public static function check(CallGraphIndex $index, array $drivers): array {
		$map = $index->constant('Doctrine\\DBAL\\DriverManager', 'DRIVER_MAP');
		if (!$map instanceof Expr\Array_) {
			throw new RuntimeException('Missing pinned Doctrine driver map');
		}
		$classes = [];
		foreach ($map->items as $item) {
			if ($item->key instanceof Node\Scalar\String_ && $item->value instanceof Expr\ClassConstFetch && $item->value->class instanceof Node\Name) {
				$classes[$item->key->value] = $item->value->class->toString();
			}
		}
		$evidence = [];
		foreach ($drivers as $name => $result) {
			$family = $name === 'oci8' ? 'OCI8' : 'PDO';
			$connection = 'Doctrine\\DBAL\\Driver\\' . $family . '\\Connection';
			$statement = 'Doctrine\\DBAL\\Driver\\' . $family . '\\Statement';
			if (!isset($classes[$name]) || $result !== 'Doctrine\\DBAL\\Driver\\' . $family . '\\Result') {
				throw new RuntimeException('Unproved driver mapping: ' . $name);
			}
			foreach ([$classes[$name] . '::connect' => $connection, $connection . '::prepare' => $statement, $statement . '::execute' => $result] as $key => $constructed) {
				$method = $index->unit($key)['node'];
				$found = (new NodeFinder())->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Expr\New_
					&& $node->class instanceof Node\Name && $node->class->toString() === $constructed);
				if ($found === []) {
					throw new RuntimeException('Missing result construction: ' . $key . ' -> ' . $constructed);
				}
				$evidence[$key] = (new Standard())->prettyPrint([$method]);
			}
		}
		ksort($evidence);
		return $evidence;
	}
}
