<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Function results come only from followed bodies or the explicit internal-function model. */
final class CallGraphFunctionTypes {
	/**
	 * @param CallGraphIndex $index indexed function bodies
	 * @param CallGraphTypes $types expression and return inference
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphTypes $types) {
	}

	/**
	 * @param string|null $class FQCN of the code
	 * @param Expr\FuncCall $expr a function call
	 * @param array<string, list<?string>> $locals local types
	 * @return list<?string> the elements an array function keeps, the return of a followed function, or no object
	 */
	public function of(?string $class, Expr\FuncCall $expr, array $locals): array {
		if (!$expr->name instanceof Node\Name) {
			return [null];
		}
		$resolved = $this->index->functionName($expr->name);
		$name = strtolower($resolved);
		if ((CallGraphFunctions::model($name)['return'] ?? null) === 'json') {
			return CallGraphJson::type($expr);
		}
		$units = $this->index->functions[$resolved] ?? [];
		if ($units !== []) {
			return CallGraphTypes::union(array_map($this->types->unitReturn(...), $units));
		}
		$model = CallGraphFunctions::model($name);
		if ($model === null) {
			return [null];
		}
		if ($name === 'array_map') {
			$callback = $expr->args[0]->value ?? null;
			if (!$callback instanceof Expr\Closure && !$callback instanceof Expr\ArrowFunction) {
				return [null];
			}
			if ($callback->returnType instanceof Node\Identifier && in_array($callback->returnType->toLowerString(), ['string', 'int', 'float', 'bool'], true)) {
				return ['scalar[]'];
			}
			$returns = $callback instanceof Expr\ArrowFunction ? [$callback->expr]
				: array_map(static fn (Stmt\Return_ $r): ?Expr => $r->expr, CallGraphTypes::returns($callback->stmts));
			$types = [];
			foreach ($returns as $value) {
				foreach ($value === null ? [''] : $this->types->typeOf($class, $value, $locals) as $type) {
					$types[] = $type === null ? null : ($type === '' ? 'scalar[]' : $type . '[]');
				}
			}
			return $types === [] ? [null] : CallGraphTypes::union($types);
		}
		$arguments = array_map(fn (Node\Arg $a): array => $a->unpack ? array_map(CallGraphTypes::element(...), $this->types->typeOf($class, $a->value, $locals)) : $this->types->typeOf($class, $a->value, $locals),
			array_values(array_filter($expr->args, static fn (Node $a): bool => $a instanceof Node\Arg)));
		$result = $model['return'];
		if ($result === 'column') {
			// Rows must themselves be arrays with proved elements. Unknown rows and object records remain unknown.
			return CallGraphTypes::union(array_map(static function (?string $rows): ?string {
				$row = CallGraphTypes::element($rows);
				return $row !== null && str_ends_with($row, '[]') ? $row : null;
			}, $arguments[0] ?? [null]));
		}
		if (in_array($name, ['array_values', 'array_reverse', 'array_slice', 'array_merge', 'array_combine'], true)) {
			$source = $name === 'array_combine' ? ($arguments[1] ?? [null]) : ($name === 'array_merge' ? array_merge(...$arguments) : ($arguments[0] ?? [null]));
			return [CallGraphArrayTypes::make([''], array_merge(...array_map(CallGraphArrayTypes::values(...), $source)))];
		}
		if (str_starts_with($result, 'preserve:')) {
			$which = substr($result, 9);
			return $which === 'all' ? CallGraphTypes::union(array_merge(...$arguments)) : ($arguments[(int)$which] ?? [null]);
		}
		if (str_starts_with($result, 'element:')) {
			return array_map(CallGraphTypes::element(...), $arguments[(int)substr($result, 8)] ?? [null]);
		}
		return match ($result) {
			'scalar' => [isset($model['scalarType']) ? CallGraphScalarTypes::declared($model['scalarType']) : ''], 'scalar-array' => ['scalar[]'],
			'arguments' => CallGraphTypes::union(array_merge(...$arguments, ...array_map(static fn (array $types): array => array_map(CallGraphTypes::element(...), $types), $arguments))),
			default => [null],
		};
	}

}
