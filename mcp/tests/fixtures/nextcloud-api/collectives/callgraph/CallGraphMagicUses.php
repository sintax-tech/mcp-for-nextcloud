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
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch, private CallGraphArguments $arguments) {
	}

	/**
	 * @param Node $node a node of a reached body
	 * @param callable(Expr):list<?string> $typeOf expression types
	 * @param callable(Node, string):void $miss reports unknown forms
	 * @return list<string> every possible implicit destination of the node
	 */
	public function calls(Node $node, callable $typeOf, callable $miss, string $caller): array {
		$edges = [];
		$add = function (Expr $value, array $hooks, array $arguments = []) use ($typeOf, $miss, $node, $caller, &$edges): void {
			foreach (CallGraphArrayTypes::leaves($typeOf($value)) as $type) {
				if ($type === 'json-object-value' && in_array('__toString', $hooks, true)) {
					$edges[] = 'php-core stdClass::__toString[absent]';
				}
				if ($type === null) {
					$miss($node, 'implicit receiver of unknown type for ' . implode('/', $hooks));
					continue;
				}
				$candidates = CallGraphTypes::isObject($type) ? $this->dispatch->instances($type) : [];
				foreach ($candidates as $class) {
					foreach ($hooks as $hook) {
						$unknown = [];
						$targets = $this->dispatch->implicit($class, $hook, $unknown);
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
		};
		if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
			$add($node->var, ['__get', '__isset'], [['']]);
		}
		if (($node instanceof Expr\Assign || $node instanceof Expr\AssignOp) && $node->var instanceof Expr\PropertyFetch) {
			$add($node->var->var, ['__set'], [[''], $typeOf($node->expr)]);
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
		if ($node instanceof Expr\BinaryOp && in_array($node->getType(), ['Expr_BinaryOp_Equal', 'Expr_BinaryOp_NotEqual',
			'Expr_BinaryOp_Smaller', 'Expr_BinaryOp_Greater', 'Expr_BinaryOp_SmallerOrEqual', 'Expr_BinaryOp_GreaterOrEqual', 'Expr_BinaryOp_Spaceship'], true)) {
			$add($node->left, ['__toString']);
			$add($node->right, ['__toString']);
		}
		if ($node instanceof Stmt\Switch_) {
			$add($node->cond, ['__toString']);
			foreach ($node->cases as $case) {
				if ($case->cond !== null) {
					$add($case->cond, ['__toString']);
				}
			}
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
			$add($node->name, ['__invoke'], array_map(static fn (Node\Arg $a): array => $typeOf($a->value), array_filter($node->args, static fn (Node $a): bool => $a instanceof Node\Arg)));
		}
		if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
			$name = strtolower($this->index->functionName($node->name));
			$model = CallGraphFunctions::model($name);
			foreach (CallGraphFunctions::stringArguments($node, $this->index) as $position) {
				if (($node->args[$position] ?? null) instanceof Node\Arg) {
					$add($node->args[$position]->value, ['__toString']);
				}
			}
			if (($model['effect'] ?? null) === 'column' && ($node->args[0] ?? null) instanceof Node\Arg) {
				$add($node->args[0]->value, ['__get', '__isset'], [['']]);
			}
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
