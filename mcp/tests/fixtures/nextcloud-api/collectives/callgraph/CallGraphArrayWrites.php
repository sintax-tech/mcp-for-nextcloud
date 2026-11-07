<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Index assignment updates every nesting level; unknown keys erase positional precision, never stored objects. */
final class CallGraphArrayWrites {
	/**
	 * @param Expr $target assigned index expression
	 * @return array{Expr,list<array{key:string|int|null,append:bool}>} base and literal/unknown index path
	 */
	public static function path(Expr $target): array {
		$path = [];
		while ($target instanceof Expr\ArrayDimFetch) {
			$key = $target->dim instanceof Node\Scalar\Int_ || $target->dim instanceof Node\Scalar\String_ ? $target->dim->value : null;
			if ($key !== null) { $key = array_key_first([$key => true]); }
			array_unshift($path, ['key' => $key, 'append' => $target->dim === null]);
			$target = $target->var;
		}
		return [$target, $path];
	}

	/**
	 * @param list<?string> $receivers existing root values; an empty list is an uninitialized local
	 * @param list<array{key:string|int|null,append:bool}> $path selected fields
	 * @param list<?string> $assigned right-hand-side evidence
	 * @return list<?string> updated container/value union without assuming an unknown receiver is an array
	 */
	public static function write(array $receivers, array $path, array $assigned): array {
		if ($path === []) { return $assigned; }
		if (count($path) > 16) { return [CallGraphArrayTypes::make([''], [null])]; }
		$step = array_shift($path);
		$result = [];
		foreach ($receivers === [] ? ['[]'] : CallGraphArrayTypes::members($receivers) as $receiver) {
			if ($receiver === '@bottom') { $result[] = '@bottom'; continue; }
			$array = CallGraphArrayTypes::read($receiver);
			if ($array === null) { $result[] = $receiver; $result[] = null; continue; }
			$key = $step['key'];
			$selected = $key !== null && $array['shape'] !== [] ? ($array['shape'][(string)$key] ?? []) : $array['values'];
			$value = self::write($selected, $path, $assigned);
			$shape = $array['shape'];
			if ($step['append'] || $key === null) {
				$shape = [];
			} else {
				$shape[(string)$key] = $value;
			}
			$result[] = CallGraphArrayTypes::make([...$array['keys'], ''], [...$array['values'], ...$value], $shape);
		}
		return CallGraphTypes::union($result);
	}
}
