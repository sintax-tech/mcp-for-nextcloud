<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/callgraph/CallGraphNames.php';
require_once __DIR__ . '/callgraph/CallGraphLiterals.php';
require_once __DIR__ . '/callgraph/CallGraphSignatures.php';
require_once __DIR__ . '/callgraph/CallGraphIndex.php';
require_once __DIR__ . '/callgraph/CallGraphDispatch.php';
require_once __DIR__ . '/callgraph/CallGraphBindings.php';
require_once __DIR__ . '/callgraph/CallGraphTypes.php';
require_once __DIR__ . '/callgraph/CallGraphLocals.php';
require_once __DIR__ . '/callgraph/CallGraphMagicUses.php';
require_once __DIR__ . '/callgraph/CallGraphCalls.php';

use PhpParser\PrettyPrinter\Standard;

/**
 * Every body an entry point reaches, read from pinned source trees without running them. Every call node of a
 * reached body is resolved from a stated type or reported as an unknown form, `file:line form` (CallGraphCalls);
 * types come from what the source states (CallGraphTypes, CallGraphLocals); a call reaches every body it can land in,
 * overrides included (CallGraphDispatch). Calls that leave the followed trees are leaves, `Type::method` or
 * `function name`; the caller checks those against an explicit allowlist.
 */
final class CollectivesCallGraph {
	private CallGraphIndex $index;
	private CallGraphDispatch $dispatch;
	private CallGraphCalls $calls;
	private Standard $printer;
	private CallGraphTypes $types;

	/**
	 * @param string|array<string, string> $lib the app's lib/ directory, whose bodies are followed; or several, by the
	 *   prefix their files are reported with (the app's own with '')
	 * @param string|null $types a tree of pinned classes and interfaces (OCP, the Circles classes the app calls) read
	 *   for types; pinned classes declaring magic hooks also have their reached bodies followed
	 */
	public function __construct(string|array $lib, ?string $types = null) {
		$this->index = new CallGraphIndex(is_string($lib) ? ['' => $lib] : $lib, $types);
		$this->dispatch = new CallGraphDispatch($this->index);
		$this->types = new CallGraphTypes($this->index, $this->dispatch);
		$this->calls = new CallGraphCalls($this->index, $this->dispatch, $this->types);
		$this->printer = new Standard();
	}

	/**
	 * @param list<string> $entries `FQCN::method` entry points
	 * @return array{methods:array<string,string>, leaves:list<string>, unknown:list<string>} reached bodies, the calls
	 *   leaving the followed trees and every form the reader could not resolve (`file:line form`)
	 */
	public function reach(array $entries): array {
		$this->types->bindings->clear();
		for ($round = 0; $round < 16; $round++) {
			$before = $this->types->bindings->snapshot();
			$methods = [];
			$leaves = [];
			$unknown = [];
			$queue = $entries;
			while ($queue !== []) {
				foreach ($this->dispatch->targets(array_shift($queue), $unknown) as $target) {
					if (!$this->dispatch->isUnit($target)) {
						$leaves[$target] = true;
					} elseif (!isset($methods[$target])) {
						$methods[$target] = $this->printer->prettyPrint([$this->index->unit($target)['node']]);
						array_push($queue, ...$this->calls->of($target, $unknown));
					}
				}
			}
			if ($this->types->bindings->snapshot() === $before) {
				break;
			}
		}
		if ($round === 16) {
			$unknown[] = 'call-site parameter evidence did not converge';
		}
		ksort($methods);
		$leaves = array_keys($leaves);
		sort($leaves);
		$unknown = array_values(array_unique($unknown));
		sort($unknown);
		return ['methods' => $methods, 'leaves' => $leaves, 'unknown' => $unknown];
	}
}
