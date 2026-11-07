<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Implicit calls are ordinary edges to pinned bodies, PHP-core leaves, or explicit unknown destinations. */
final class CallGraphMagicUses {
	/**
	 * @param CallGraphIndex $index pinned declarations
	 * @param CallGraphDispatch $dispatch ordinary and implicit destinations
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch) {
	}

	/**
	 * @param Node $node a node of a reached body
	 * @param callable(Expr):list<?string> $typeOf expression types
	 * @param callable(Node, string):void $miss reports unknown forms
	 * @return list<string> every possible implicit destination of the node
	 */
	public function calls(Node $node, callable $typeOf, callable $miss): array {
		$edges = [];
		$add = function (Expr $value, array $hooks) use ($typeOf, $miss, $node, &$edges): void {
			foreach ($typeOf($value) as $type) {
				if ($type === null) {
					$miss($node, 'implicit receiver of unknown type for ' . implode('/', $hooks));
					continue;
				}
				$candidates = CallGraphTypes::isObject($type) ? $this->dispatch->instances($type) : [];
				foreach ($candidates as $class) {
					foreach ($hooks as $hook) {
						$unknown = [];
						array_push($edges, ...$this->dispatch->implicit($class, $hook, $unknown));
						foreach ($unknown as $message) {
							$miss($node, $message);
						}
					}
				}
			}
		};
		if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
			$add($node->var, ['__get']);
		}
		if (($node instanceof Expr\Assign || $node instanceof Expr\AssignOp) && $node->var instanceof Expr\PropertyFetch) {
			$add($node->var->var, ['__set']);
		}
		if ($node instanceof Expr\Isset_ || $node instanceof Stmt\Unset_) {
			foreach ($node->vars as $var) {
				if ($var instanceof Expr\PropertyFetch) {
					$add($var->var, [$node instanceof Expr\Isset_ ? '__isset' : '__unset']);
				}
			}
		}
		if ($node instanceof Expr\Clone_) {
			$add($node->expr, ['__clone']);
		}
		$strings = match (true) {
			$node instanceof Expr\BinaryOp\Concat => [$node->left, $node->right],
			$node instanceof Expr\AssignOp\Concat => [$node->var, $node->expr],
			$node instanceof Node\Scalar\InterpolatedString => array_filter($node->parts, static fn (Node $p): bool => $p instanceof Expr),
			$node instanceof Expr\Cast\String_, $node instanceof Expr\Print_ => [$node->expr],
			$node instanceof Stmt\Echo_ => $node->exprs,
			default => [],
		};
		foreach ($strings as $value) {
			$add($value, ['__toString']);
		}
		if ($node instanceof Expr\FuncCall && $node->name instanceof Expr) {
			$add($node->name, ['__invoke']);
		}
		if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
			$name = strtolower($node->name->toString());
			if ($name === 'serialize' && ($node->args[0] ?? null) instanceof Node\Arg) {
				$add($node->args[0]->value, ['__serialize', '__sleep']);
			} elseif ($name === 'unserialize') {
				// A serialized string does not state its runtime class. Never guess that its hooks are absent.
				$miss($node, 'unserialize of a class not statically established');
				foreach ($this->index->classes as $class => $info) {
					foreach ($info['outside'] ? [] : ['__unserialize', '__wakeup'] as $hook) {
						$unknown = [];
						array_push($edges, ...$this->dispatch->implicit($class, $hook, $unknown));
					}
				}
			}
		}
		return array_values(array_unique($edges));
	}
}
