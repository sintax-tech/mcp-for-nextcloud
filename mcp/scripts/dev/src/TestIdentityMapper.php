<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Finds where a test class of the baseline lives now.
 *
 * In order: the same name; the name derived from the FQCN map (the test of `OCA\Mcp\X\Foo` is
 * `OCA\Mcp\Tests\Unit\X\FooTest`, so it follows every later name of `Foo` in the map, and a test named after a
 * class that the map holds only once follows that class); and, last, a test class whose short name is unique in
 * both inventories. The rule that matched is reported, so a reviewer sees which identities were not exact.
 */
final class TestIdentityMapper {
	/** Namespace of the unit tests, mirroring `lib/` under the app prefix. */
	private const TESTS = 'Tests\\Unit\\';

	/** @var array<string,list<string>> lower-case short class name => map entries' chains with that short name */
	private array $byShortName = [];

	/**
	 * @param FqcnMap $map the map
	 * @param list<string> $baselineClasses test classes of the baseline
	 * @param list<string> $currentClasses test classes now
	 */
	public function __construct(
		private readonly FqcnMap $map,
		private readonly array $baselineClasses,
		private readonly array $currentClasses,
	) {
		foreach ($map->entries as $entry) {
			foreach ($map->chainsOf($entry) as $chain) {
				$this->byShortName[strtolower(self::short($chain[0]))][] = $chain;
			}
		}
	}

	/**
	 * Current name of a baseline test class.
	 *
	 * @param string $class baseline fully qualified name
	 * @return array{0:string,1:string}|null current name and the rule that found it (`exact`, `map`, `short-name`)
	 */
	public function current(string $class): ?array {
		$now = array_flip($this->currentClasses);
		if (isset($now[$class])) {
			return [$class, 'exact'];
		}
		foreach ($this->candidates($class) as $candidate) {
			if (isset($now[$candidate])) {
				return [$candidate, 'map'];
			}
		}
		$short = self::short($class);
		$before = array_values(array_filter($this->baselineClasses, static fn (string $c): bool => self::short($c) === $short));
		$after = array_values(array_filter($this->currentClasses, static fn (string $c): bool => self::short($c) === $short));
		return count($before) === 1 && count($after) === 1 ? [$after[0], 'short-name'] : null;
	}

	/**
	 * Test names the map predicts for a test class.
	 *
	 * @param string $class baseline fully qualified test class name
	 * @return list<string> candidate current names
	 */
	private function candidates(string $class): array {
		$tests = $this->map->prefix . self::TESTS;
		if (!str_starts_with($class, $tests) || !str_ends_with($class, 'Test')) {
			return [];
		}
		$subject = $this->map->prefix . substr($class, strlen($tests), -4);
		$chains = $this->map->knows($subject) ? array_filter($this->byShortName[strtolower(self::short($subject))] ?? [], static fn (array $chain): bool => in_array($subject, $chain, true)) : [];
		if ($chains === [] && count($this->byShortName[strtolower(self::short($subject))] ?? []) === 1) {
			$chains = $this->byShortName[strtolower(self::short($subject))];
		}
		$found = [];
		foreach ($chains as $chain) {
			foreach ($chain as $step) {
				$found[] = $tests . substr($step, strlen($this->map->prefix)) . 'Test';
			}
		}
		return $found;
	}

	/**
	 * Last segment of a fully qualified name.
	 *
	 * @param string $class fully qualified name
	 * @return string short name
	 */
	private static function short(string $class): string {
		$position = strrpos($class, '\\');
		return $position === false ? $class : substr($class, $position + 1);
	}
}
