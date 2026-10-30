<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Compares a baseline of `Class::method` identities with the current ones through {@see TestIdentityMapper}.
 *
 * Used for the declared test methods (every baseline method must still exist) and for the failures of two JUnit
 * reports (the failure set must be the same, once the classes are mapped).
 */
final class TestInventoryComparison {
	/** @var array<string,int> how many baseline identities each rule matched (`exact`, `map`) */
	public array $rules = ['exact' => 0, 'map' => 0];

	/**
	 * @param FqcnMap $map the map of the current tree
	 */
	public function __construct(private readonly FqcnMap $map) {
	}

	/**
	 * Baseline identities with no current counterpart.
	 *
	 * @param list<string> $baseline `Class::method` of the baseline
	 * @param list<string> $current `Class::method` now
	 * @return list<string> missing baseline identities, with the current name tried when one was found
	 */
	public function missing(array $baseline, array $current): array {
		$mapper = new TestIdentityMapper($this->map, self::classes($baseline), self::classes($current));
		$now = array_flip($current);
		$missing = [];
		foreach ($baseline as $identity) {
			[$class, $method] = explode('::', $identity, 2);
			$found = $mapper->current($class);
			if ($found !== null && isset($now[$found[0] . '::' . $method])) {
				$this->rules[$found[1]]++;
				continue;
			}
			$lookAlike = $found === null ? $mapper->shortNameMatch($class) : null;
			$missing[] = match (true) {
				$found !== null => "$identity (looked for {$found[0]}::$method)",
				$lookAlike !== null => "$identity (only $lookAlike has the same short name; list the move under \"tests\" in the FQCN map)",
				default => $identity,
			};
		}
		return $missing;
	}

	/**
	 * Differences between the failures of two reports.
	 *
	 * @param JunitReport $baseline report of the baseline
	 * @param JunitReport $current report now
	 * @return array{gone:list<string>,new:list<string>} baseline failures not failing now, and new failures
	 */
	public function failures(JunitReport $baseline, JunitReport $current): array {
		$mapper = new TestIdentityMapper($this->map, $baseline->classes(), $current->classes());
		$expected = [];
		foreach ($baseline->failures() as $identity) {
			[$class, $name] = explode('::', $identity, 2);
			$expected[] = ($mapper->current($class)[0] ?? $class) . '::' . $name;
		}
		$actual = $current->failures();
		return ['gone' => array_values(array_diff($expected, $actual)), 'new' => array_values(array_diff($actual, $expected))];
	}

	/**
	 * Classes of a list of identities.
	 *
	 * @param list<string> $identities `Class::method`
	 * @return list<string> distinct classes
	 */
	private static function classes(array $identities): array {
		return array_values(array_unique(array_map(static fn (string $id): string => explode('::', $id, 2)[0], $identities)));
	}
}
