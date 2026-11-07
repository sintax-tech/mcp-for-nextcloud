<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;

/** Shared dispatch of implicit magic calls; unknown values are never turned into absent hooks. */
final class CallGraphImplicitEdges {
	/**
	 * @param CallGraphDispatch $dispatch target resolution
	 * @param CallGraphArguments $arguments caller evidence
	 */
	public function __construct(private CallGraphDispatch $dispatch, private CallGraphArguments $arguments) {
	}

	/**
	 * @param list<?string> $types receiver/value alternatives
	 * @param list<string> $hooks implicit method names
	 * @param Node $node triggering syntax
	 * @param string $caller canonical caller
	 * @param callable(Node,string):void $miss source-positioned rejection
	 * @param list<list<?string>> $arguments actual hook parameters
	 * @param bool $elements whether the operation converts/comparisons array elements as well
	 * @return list<string> available bodies and verified PHP-core leaves
	 */
	public function of(array $types, array $hooks, Node $node, string $caller, callable $miss, array $arguments = [], bool $elements = true): array {
		$edges = [];
		foreach ($elements ? CallGraphArrayTypes::leaves($types) : CallGraphArrayTypes::members($types) as $type) {
			if ($type === 'json-object-value' && in_array('__toString', $hooks, true)) {
				$edges[] = 'php-core stdClass::__toString[absent]';
			}
			if ($type === null || $type === '@bottom') {
				$miss($node, 'implicit receiver of unknown type for ' . implode('/', $hooks));
				continue;
			}
			foreach (CallGraphTypes::isObject($type) ? $this->dispatch->instances($type) : [] as $class) {
				foreach ($hooks as $hook) {
					$unknown = [];
					$targets = $this->dispatch->implicit($class, $hook, $unknown);
					foreach ($targets as $target) {
						if (str_starts_with($target, 'php-core ')) {
							$catalog = json_decode(file_get_contents(dirname(__DIR__) . '/collectives-external-leaves.json'), true, 512, JSON_THROW_ON_ERROR);
							if (!isset($catalog[$target])) { $miss($node, 'implicit destination has no pinned or audited core body: ' . $target); }
						}
					}
					array_push($edges, ...$targets);
					foreach ($targets as $target) {
						$this->arguments->bind($target, $caller . ':' . $node->getStartFilePos() . ':' . $hook, $arguments);
					}
					foreach ($unknown as $message) {
						$miss($node, $message);
					}
				}
			}
		}
		return array_values(array_unique($edges));
	}
}
