<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Every admitted internal function has an explicit result model and an explicit implicit-call policy. */
final class CallGraphFunctions {
	/** @var array<string, array{return:string,effect:string,arguments:array|string}>|null */
	private static ?array $functions = null;

	/**
	 * @param string $name a function name
	 * @return array{return:string,effect:string,arguments:array|string}|null its explicit model, never a default scalar
	 */
	public static function model(string $name): ?array {
		self::$functions ??= json_decode(file_get_contents(dirname(__DIR__) . '/collectives-php-functions.json'), true, 512, JSON_THROW_ON_ERROR);
		return self::$functions[strtolower($name)] ?? null;
	}

	/**
	 * @param Expr\FuncCall $call a listed call
	 * @param CallGraphIndex $index pinned constant values
	 * @return bool whether its model can convert an object to string in this call
	 */
	public static function stringifies(Expr\FuncCall $call, CallGraphIndex $index): bool {
		$model = $call->name instanceof Node\Name ? self::model($index->functionName($call->name)) : null;
		if (($model['effect'] ?? null) !== 'stringify') {
			return false;
		}
		if (isset($model['condition'])) {
			$value = $call->args[$model['condition']['argument']]->value ?? null;
			if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier) {
				$value = $index->constant($value->class->toString(), $value->name->name);
			}
			if ($value instanceof Node\Scalar\String_) {
				return in_array($value->value, $model['condition']['values'], true);
			}
		}
		return true;
	}

	/**
	 * @param Expr\FuncCall $call a call to an internal function
	 * @param callable(Node, string):void $miss reports an unsupported callable or absent model
	 * @return void inline closures are traversed normally; every other callback remains outside this subset
	 */
	public static function check(Expr\FuncCall $call, callable $miss): void {
		$name = $call->name instanceof Node\Name ? $call->name->toString() : '';
		$model = self::model($name);
		if ($model === null) {
			$miss($call, 'function outside the closed subset: ' . $name);
			return;
		}
		foreach ($model['effect'] === 'callback' ? $model['arguments'] : [] as $position) {
			if (!isset($call->args[$position]) && in_array(strtolower($name), ['array_filter', 'ob_start'], true)) {
				continue;
			}
			$callback = $call->args[$position]->value ?? null;
			if (!$callback instanceof Expr\Closure && !$callback instanceof Expr\ArrowFunction) {
				$miss($call, $name . '( with a callable outside the closed subset');
			}
		}
	}
}
