<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Interprocedural return estimates are recomputed to a fixed point; recursive bottom never becomes a scalar guess. */
final class CallGraphReturns {
	/** @var array<string,?string> previous/current return estimates */
	private array $values = [];
	/** @var array<string,true> active and completed bodies in this inference round */
	private array $active = [], $done = [];

	/**
	 * @param CallGraphIndex $index declarations
	 * @param CallGraphTypes $types expression and local inference
	 */
	public function __construct(private CallGraphIndex $index, private CallGraphTypes $types) {
	}

	/** @return void starts an independent reach query */
	public function clear(): void { $this->values = $this->active = $this->done = []; }
	/** @return void starts one inference round, preserving recursive estimates from the previous round */
	public function begin(): void { $this->active = $this->done = []; }
	/** @return string convergence fingerprint */
	public function snapshot(): string { $values = $this->values; ksort($values); return serialize($values); }
	/** @return list<string> recursion without a proved return seed remains explicitly unresolved */
	public function unresolved(): array { return array_keys(array_filter($this->values, static fn (?string $type): bool => $type === '@bottom')); }

	/**
	 * @param string $key canonical body
	 * @return string|null native declaration or the union of all proved return values, including array shapes
	 */
	public function of(string $key): ?string {
		$unit = $this->index->unit($key);
		$native = (new CallGraphSignatures($this->index))->declared($unit['node']->returnType, $unit['class']);
		if ($native !== null) { return $native; }
		if ($unit['node']->stmts === null) { return $unit['returns']; }
		if (isset($this->active[$key]) || isset($this->done[$key])) { return array_key_exists($key, $this->values) ? $this->values[$key] : '@bottom'; }
		$this->active[$key] = true;
		try {
			$locals = $this->types->locals->of($key);
			$types = [];
			foreach (CallGraphTypes::returns($unit['node']->stmts) as $return) {
				array_push($types, ...($return->expr === null ? [''] : $this->types->typeOf($unit['class'], $return->expr, $locals)));
			}
			$types = CallGraphTypes::union($types);
			if (count($types) > 1) { $types = array_values(array_filter($types, static fn (?string $t): bool => $t !== '@bottom')); }
			$declared = $unit['returns'];
			$hasUnknown = in_array(null, $types, true) || array_filter($types, static fn (?string $t): bool => in_array(null, CallGraphArrayTypes::read($t)['values'] ?? [], true)) !== [];
			if ($declared !== null) {
				// A declared doc contract proves an otherwise unknown return, but never erases concrete contrary evidence.
				$types = array_values(array_filter($types, static fn (?string $t): bool => $t !== null && !(CallGraphArrayTypes::read($t) !== null
					&& in_array(null, CallGraphArrayTypes::read($t)['values'], true))));
				foreach (CallGraphArrayTypes::members([$declared]) as $contract) {
					$generic = in_array(null, CallGraphArrayTypes::read($contract)['values'] ?? [], true);
					if (!$generic || $hasUnknown || $types === []) { $types[] = $contract; }
				}
			}
			if ($unit['node']->returnType instanceof Node\Identifier && $unit['node']->returnType->toLowerString() === 'array') {
				$types = array_map(static fn (?string $t): ?string => in_array($t, ['json-value', 'json-object-value'], true) ? CallGraphArrayTypes::make([''], [$t]) : $t, $types);
				$types = array_values(array_filter($types, static fn (?string $t): bool => $t === '@bottom' || CallGraphArrayTypes::read($t) !== null));
				if ($types === []) { $types = [CallGraphArrayTypes::make([''], [null])]; }
			}
			$this->values[$key] = $types === [] ? '' : CallGraphArrayTypes::pack(CallGraphTypes::union($types));
			$this->done[$key] = true;
			return $this->values[$key];
		} finally {
			unset($this->active[$key]);
		}
	}
}
