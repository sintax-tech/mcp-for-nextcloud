<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** All reached callers contribute argument evidence to ordinary and implicit method bodies as well as functions. */
final class CallGraphArguments {
	/**
	 * @param CallGraphIndex $index pinned declarations
	 * @param CallGraphDispatch $dispatch resolved bodies
	 * @param CallGraphTypes $types shared parameter evidence
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphDispatch $dispatch, private CallGraphTypes $types) {
	}

	/**
	 * @param string $target canonical destination
	 * @param string $site caller and source position
	 * @param list<list<?string>> $values supplied arguments, by position
	 * @return void records only a body actually followed by the graph
	 */
	public function bind(string $target, string $site, array $values): void {
		if (!$this->dispatch->isUnit($target)) {
			return;
		}
		$arguments = [];
		foreach ($this->index->unit($target)['node']->params as $position => $param) {
			if (isset($values[$position])) {
				$arguments[$param->var->name] = $values[$position];
			} elseif ($param->default === null) {
				$arguments[$param->var->name] = [null];
			}
			if ($param->default !== null) {
				$default = CallGraphLiterals::type($param->default);
				$arguments[$param->var->name] = CallGraphTypes::union([...($arguments[$param->var->name] ?? []), $default]);
			}
		}
		$this->types->bindings->record($target, $site, $arguments);
	}

	/**
	 * @param Node $node a reached call
	 * @param string $caller canonical caller key
	 * @param string|null $class containing class
	 * @param callable(Expr):list<?string> $typeOf local expression evidence
	 * @return void preserves the union of arguments across every possible destination and caller
	 */
	public function record(Node $node, string $caller, ?string $class, callable $typeOf): void {
		$calls = [];
		$name = '';
		if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
			$calls = ['function ' . $this->index->functionName($node->name)];
		} elseif (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
			$name = $node->name->toLowerString();
			foreach ($typeOf($node->var) as $receiver) {
				if (CallGraphTypes::isObject($receiver)) {
					$calls[] = $receiver . '::' . $node->name->name;
				}
			}
		} elseif ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
			$name = $node->name->toLowerString();
			$calls = ['static ' . $this->index->name($class, $node->class) . '::' . $node->name->name];
		} elseif ($node instanceof Expr\New_) {
			foreach ($typeOf($node) as $receiver) {
				if (CallGraphTypes::isObject($receiver)) {
					$calls[] = $receiver . '::__construct';
				}
			}
		} else {
			return;
		}
		$values = [];
		$packed = [];
		foreach ($node->args as $arg) {
			if (!$arg instanceof Node\Arg) {
				continue;
			}
			$types = $typeOf($arg->value);
			$values[] = $arg->unpack ? [null] : $types;
			array_push($packed, ...($arg->unpack ? $types : array_map(static fn (?string $t): ?string => $t === null ? null : ($t === '' ? 'scalar[]' : $t . '[]'), $types)));
		}
		foreach ($calls as $call) {
			$ignored = [];
			foreach ($this->dispatch->targets($call, $ignored) as $target) {
				$method = strtolower(explode('::', $target)[1] ?? '');
				$magic = in_array($method, ['__call', '__callstatic'], true) && $method !== $name;
				$args = $magic ? [[''], $packed === [] ? ['[]'] : CallGraphTypes::union($packed)] : $values;
				$this->bind($target, $caller . ':' . $node->getStartFilePos() . ':' . $call, $args);
			}
		}
	}
}
