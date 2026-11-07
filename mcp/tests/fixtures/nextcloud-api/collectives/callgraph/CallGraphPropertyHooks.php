<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;

/** Property-hook syntax remains outside the model until its get/set bodies are explicitly followed. */
final class CallGraphPropertyHooks {
	/**
	 * @param list<?string> $receivers possible receiver classes
	 * @param string|null $field literal property name, or null when any property can be selected/compared
	 * @param Node $site triggering syntax
	 * @param CallGraphIndex $index pinned declarations
	 * @param CallGraphDispatch $dispatch possible concrete receiver classes
	 * @param callable(Node,string):void $miss source-positioned rejection
	 * @return void refuses hooks in the class, subclasses, ancestors and traits rather than ignoring their bodies
	 */
	public static function check(array $receivers, ?string $field, Node $site, CallGraphIndex $index, CallGraphDispatch $dispatch, callable $miss): void {
		foreach ($receivers as $receiver) {
			foreach (CallGraphTypes::isObject($receiver) ? $dispatch->instances($receiver) : [] as $candidate) {
				$todo = [$candidate, ...$index->ancestors($candidate)];
				$seen = [];
				while ($todo !== []) {
					$owner = array_pop($todo);
					if (isset($seen[$owner])) { continue; }
					$seen[$owner] = true;
					$info = $index->classes[$owner] ?? [];
					array_push($todo, ...($info['traits'] ?? []));
					foreach (($info['node'] ?? null)?->getProperties() ?? [] as $property) {
						foreach ($property->props as $prop) {
							if (($property->hooks ?? []) !== [] && ($field === null || $prop->name->name === $field)) {
								$miss($site, 'property hooks outside the closed subset: ' . $owner . '::$' . $prop->name->name);
							}
						}
					}
				}
			}
		}
	}
}
