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
	 * @param Expr\FuncCall $call listed call
	 * @param CallGraphIndex $index pinned constants and function resolution
	 * @return list<int> argument positions that can trigger string conversion or loose comparison
	 */
	public static function stringArguments(Expr\FuncCall $call, CallGraphIndex $index): array {
		$model = $call->name instanceof Node\Name ? self::model($index->functionName($call->name)) : null;
		if (!in_array($model['effect'] ?? null, ['stringify', 'compare', 'column'], true)) {
			return [];
		}
		if (isset($model['requiredArgument']) && !isset($call->args[$model['requiredArgument']])) {
			return [];
		}
		$strict = isset($model['strictArgument']) ? ($call->args[$model['strictArgument']]->value ?? null) : null;
		if ($strict instanceof Expr\ConstFetch && strtolower($strict->name->toString()) === 'true') {
			return [];
		}
		$positions = $model['arguments'] === 'all' ? array_keys($call->args) : $model['arguments'];
		if (isset($model['condition'])) {
			$value = $call->args[$model['condition']['argument']]->value ?? null;
			if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier) {
				$value = $index->constant($value->class->toString(), $value->name->name);
			}
			if ($value instanceof Node\Scalar\String_ && !in_array($value->value, $model['condition']['values'], true)) {
				$positions = [];
			}
		}
		return array_values(array_unique([...$positions, ...($model['alwaysArguments'] ?? [])]));
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
