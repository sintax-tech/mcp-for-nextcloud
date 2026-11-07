<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** PHP object protocols are implicit calls, with the same argument evidence and body requirement as explicit calls. */
final class CallGraphProtocols {
	/**
	 * @param CallGraphIndex $index declarations
	 * @param CallGraphDispatch $dispatch body resolution
	 * @param CallGraphTypes $types return inference
	 * @param CallGraphArguments $arguments call-site parameter evidence
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch,
		private CallGraphTypes $types, private CallGraphArguments $arguments) {
	}

	/**
	 * @param Node $node reached syntax
	 * @param callable(Expr):list<?string> $typeOf local type evidence
	 * @param callable(Node,string):void $miss reports a source-positioned unknown
	 * @param string $caller canonical caller
	 * @return list<string> implicit protocol bodies
	 */
	public function calls(Node $node, callable $typeOf, callable $miss, string $caller): array {
		$edges = [];
		$follow = function (string $class, string $method, array $values = []) use ($node, $miss, $caller, &$edges): void {
			$owner = $this->dispatch->owner($class, $method);
			$target = $owner === null ? null : $owner . '::' . $this->index->methodName($owner, $method);
			if ($target === null || !$this->dispatch->isUnit($target)) {
				$miss($node, 'protocol ' . $class . '::' . $method . ' has no pinned body');
				return;
			}
			$edges[] = $target;
			$this->arguments->bind($target, $caller . ':' . $node->getStartFilePos() . ':protocol:' . $class . '::' . $method, $values);
		};
		if ($node instanceof Stmt\Foreach_) {
			foreach ($typeOf($node->expr) as $receiver) {
				if ($receiver === null) {
					$miss($node, 'foreach receiver of unknown type');
				} elseif (CallGraphTypes::isObject($receiver)) {
					foreach ($this->dispatch->instances($receiver) as $class) {
						$this->iterate($class, $follow, static fn (string $why) => $miss($node, $why));
					}
				}
			}
		}
		if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name
			&& (CallGraphFunctions::model($this->index->functionName($node->name))['effect'] ?? null) === 'count') {
			foreach (isset($node->args[0]) ? $typeOf($node->args[0]->value) : [null] as $receiver) {
				if ($receiver === null) {
					$miss($node, 'count receiver of unknown type');
				} elseif (CallGraphTypes::isObject($receiver)) {
					foreach ($this->dispatch->instances($receiver) as $class) {
						if ($this->implements($class, 'Countable')) {
							$follow($class, 'count');
						}
					}
				}
			}
		}
		if ($node instanceof Expr\ArrayDimFetch) {
			$context = $node;
			$parent = $context->getAttribute('parent');
			$nested = false;
			while ($parent instanceof Expr\ArrayDimFetch && $parent->var === $context) {
				$nested = true;
				$context = $parent;
				$parent = $context->getAttribute('parent');
			}
			$hooks = ['offsetGet'];
			$key = $node->dim === null ? [''] : $typeOf($node->dim);
			$values = [$key];
			if ($parent instanceof Expr\Isset_) {
				$hooks = $nested ? ['offsetExists', 'offsetGet'] : ['offsetExists'];
			} elseif ($parent instanceof Stmt\Unset_) {
				$hooks = ['offsetUnset'];
			} elseif ($parent instanceof Expr\Empty_ || $parent instanceof Expr\BinaryOp\Coalesce && $parent->left === $context) {
				$hooks = ['offsetExists', 'offsetGet'];
			} elseif (($parent instanceof Expr\Assign || $parent instanceof Expr\AssignOp) && $parent->var === $context) {
				$hooks = $parent instanceof Expr\Assign ? ['offsetSet'] : ['offsetGet', 'offsetSet'];
				if ($parent instanceof Expr\AssignOp\Coalesce) {
					$hooks[] = 'offsetExists';
				}
				$values[] = $typeOf($parent->expr);
			}
			if ($parent instanceof Expr\PreInc || $parent instanceof Expr\PreDec || $parent instanceof Expr\PostInc || $parent instanceof Expr\PostDec) {
				$hooks = ['offsetGet', 'offsetSet'];
				$values[] = $typeOf($parent);
			}
			foreach ($typeOf($node->var) as $receiver) {
				if ($receiver === null) {
					$miss($node, 'array access receiver of unknown type');
				} elseif (CallGraphTypes::isObject($receiver)) {
					foreach ($this->dispatch->instances($receiver) as $class) {
						foreach ($hooks as $hook) {
							$follow($class, $hook, $hook === 'offsetSet' ? $values : [$key]);
						}
					}
				}
			}
		}
		if ($node instanceof Expr\List_ || $node instanceof Expr\Array_) {
			$parent = $node->getAttribute('parent');
			$values = null;
			if ($parent instanceof Expr\Assign && $parent->var === $node) {
				$values = $typeOf($parent->expr);
			} elseif ($parent instanceof Stmt\Foreach_ && $parent->valueVar === $node) {
				$values = array_merge(...array_map(CallGraphArrayTypes::values(...), $typeOf($parent->expr)));
			}
			if ($values !== null) {
				$this->destructure($node, $values, $typeOf, $follow, static fn (string $why) => $miss($node, $why));
			}
		}
		return array_values(array_unique($edges));
	}

	/**
	 * @param Expr\List_|Expr\Array_ $pattern destructuring target
	 * @param list<?string> $values possible source values
	 * @param callable(Expr):list<?string> $typeOf key evidence
	 * @param callable(string,string,array):void $follow follows offsetGet
	 * @param callable(string):void $miss reports unproved receivers
	 * @return void destructuring reads ArrayAccess objects as well as ordinary arrays
	 */
	private function destructure(Expr $pattern, array $values, callable $typeOf, callable $follow, callable $miss): void {
		foreach ($pattern->items as $position => $item) {
			if ($item === null) { continue; }
			$elements = [];
			foreach (CallGraphArrayTypes::members($values) as $value) {
				if ($value === null) { $miss('destructuring receiver of unknown type'); }
				if (CallGraphTypes::isObject($value)) {
					foreach ($this->dispatch->instances($value) as $class) {
						$follow($class, 'offsetGet', [$item->key === null ? ['@number:int'] : $typeOf($item->key)]);
						array_push($elements, ...$this->types->returnTypes($class, 'offsetGet'));
					}
				} else {
					array_push($elements, ...CallGraphArrayTypes::values($value, $item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_ ? $item->key->value : $position));
				}
			}
			if ($item->value instanceof Expr\List_ || $item->value instanceof Expr\Array_) {
				$this->destructure($item->value, $elements, $typeOf, $follow, $miss);
			}
		}
	}

	/**
	 * @param string $class receiver
	 * @param string $interface PHP protocol
	 * @return bool whether the declaration or PHP's internal ancestry proves this protocol
	 */
	private function implements(string $class, string $interface): bool {
		foreach ([$class, ...$this->index->ancestors($class)] as $ancestor) {
			if ($ancestor === $interface || (class_exists($ancestor, false) && is_subclass_of($ancestor, $interface))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $class iterator or aggregate
	 * @param callable(string,string):void $follow follows each protocol body
	 * @param callable(string):void $miss reports unknown iterator returns
	 * @param list<string> $seen recursion guard
	 * @return list<string> concrete Iterator candidates for element inference
	 */
	private function iterate(string $class, callable $follow, callable $miss, array $seen = []): array {
		if (in_array($class, $seen, true)) {
			$miss('recursive iterator protocol without a proved concrete iterator');
			return [];
		}
		if ($this->implements($class, 'IteratorAggregate')) {
			$follow($class, 'getIterator');
			$iterators = [];
			foreach ($this->iteratorReturns($class) as $type) {
				if (!CallGraphTypes::isObject($type)) {
					$miss('getIterator return of unknown or non-object type');
					continue;
				}
				foreach ($this->dispatch->instances($type) as $target) {
					array_push($iterators, ...$this->iterate($target, $follow, $miss, [...$seen, $class]));
				}
			}
			return $iterators;
		}
		if ($this->implements($class, 'Iterator')) {
			foreach (['current', 'next', 'key', 'valid', 'rewind'] as $method) {
				$follow($class, $method);
			}
			return [$class];
		}
		if (in_array($class, ['Traversable', 'IteratorAggregate'], true) || !isset($this->index->classes[$class])) {
			$miss('iterator destination has no proved concrete body: ' . $class);
		}
		return [];
	}

	/**
	 * @param string $class concrete aggregate
	 * @return list<?string> concrete values returned by the available body, refining its erased Traversable declaration
	 */
	private function iteratorReturns(string $class): array {
		$owner = $this->dispatch->owner($class, 'getIterator');
		if ($owner === null) {
			return [null];
		}
		$key = $owner . '::' . $this->index->methodName($owner, 'getIterator');
		$unit = $this->index->unit($key);
		$locals = $this->types->locals->of($key);
		$types = [];
		foreach (CallGraphTypes::returns($unit['node']->stmts ?? []) as $return) {
			array_push($types, ...($return->expr === null ? [null] : $this->types->typeOf($class, $return->expr, $locals)));
		}
		return $types === [] ? [null] : CallGraphTypes::union($types);
	}

	/**
	 * @param string $class object used in foreach
	 * @return array{list<?string>,list<?string>} keys and current values; unproved public-property iteration stays unknown
	 */
	public function elements(string $class): array {
		$iterators = $this->iterate($class, static fn () => null, static fn () => null);
		$keys = [];
		$values = [];
		foreach ($iterators as $iterator) {
			array_push($keys, ...$this->types->returnTypes($iterator, 'key'));
			array_push($values, ...$this->types->returnTypes($iterator, 'current'));
		}
		return [$keys === [] ? [null] : CallGraphTypes::union($keys), $values === [] ? [null] : CallGraphTypes::union($values)];
	}
}
