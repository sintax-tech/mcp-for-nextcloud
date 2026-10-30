<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

/**
 * Compares a PHPUnit JUnit report with the RE-01 baseline: every baseline identity (`Class::method`, data set
 * included) must have RUN — a test reported as skipped or incomplete did not run —, the skipped/incomplete tests must
 * be exactly those of the baseline, and the failing tests exactly its environment failures.
 *
 * An optional FQCN map (old => new, from RE-01b) renames baseline classes before the comparison.
 */
final class InventoryCheck {
	/** @var list<string> differences found, one line each; empty when the report holds the baseline */
	public array $problems = [];
	public int $ran = 0;
	public int $failing = 0;
	public int $skipped = 0;

	/**
	 * @param list<string> $inventory baseline identities
	 * @param list<string> $failures baseline failing identities
	 * @param list<string> $skips baseline skipped/incomplete identities
	 * @param array<string, string> $map old FQCN => new FQCN
	 */
	public function __construct(private array $inventory, private array $failures, private array $skips, private array $map = []) {
	}

	/**
	 * @param string $dir directory of the baseline files (tests-inventory.txt, environment-failures.txt, skipped.txt)
	 * @param array<string, string> $map old FQCN => new FQCN
	 * @return self the check over that baseline
	 */
	public static function fromBaseline(string $dir, array $map = []): self {
		$lines = static function (string $file) use ($dir): array {
			$path = $dir . '/' . $file;
			if (!is_file($path) || !is_readable($path)) {
				throw new \RuntimeException('Baseline file is missing or unreadable: ' . $path);
			}
			$content = file_get_contents($path);
			if ($content === false) {
				throw new \RuntimeException('Cannot read baseline file: ' . $path);
			}
			return array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
		};
		$first = static fn (array $rows): array => array_map(static fn (string $row): string => explode("\t", $row)[0], $rows);
		return new self($lines('tests-inventory.txt'), $first($lines('environment-failures.txt')), $first($lines('skipped.txt')), $map);
	}

	/**
	 * @param string $xml the JUnit report
	 * @return bool true when the report runs the whole baseline and fails/skips exactly where it did
	 */
	public function check(string $xml): bool {
		$this->problems = [];
		$previous = libxml_use_internal_errors(true);
		$report = simplexml_load_string($xml);
		libxml_use_internal_errors($previous);
		$cases = $report === false ? false : $report->xpath('//testcase');
		if ($cases === false || $cases === null || $cases === []) {
			$this->problems[] = 'not a JUnit report with test cases';
			return false;
		}
		$ran = [];
		$failing = [];
		$skipped = [];
		foreach ($cases as $case) {
			$id = (string)$case['class'] . '::' . (string)$case['name'];
			if (isset($case->skipped) || isset($case->incomplete)) {
				$skipped[$id] = true;
				continue;
			}
			$ran[$id] = true;
			if (isset($case->failure) || isset($case->error)) {
				$failing[$id] = true;
			}
		}
		[$this->ran, $this->failing, $this->skipped] = [count($ran), count($failing), count($skipped)];
		$expectedSkips = $this->renamed($this->skips);
		$this->report('missing from the run', array_diff($this->renamed($this->inventory), array_keys($ran), $expectedSkips));
		$this->report('skipped but not in the baseline', array_diff(array_keys($skipped), $expectedSkips));
		$this->report('baseline skip that ran or vanished', array_diff($expectedSkips, array_keys($skipped)));
		$this->report('failing but not in the baseline', array_diff(array_keys($failing), $this->renamed($this->failures)));
		$this->report('baseline failure no longer failing', array_diff($this->renamed($this->failures), array_keys($failing)));
		return $this->problems === [];
	}

	/**
	 * @param list<string> $ids baseline identities
	 * @return list<string> the same identities under the new class names of the map
	 */
	private function renamed(array $ids): array {
		return array_map(function (string $id): string {
			[$class, $rest] = explode('::', $id, 2) + [1 => ''];
			return ($this->map[$class] ?? $class) . '::' . $rest;
		}, $ids);
	}

	/**
	 * @param string $label kind of difference
	 * @param array<int, string> $ids identities with that difference
	 * @return void
	 */
	private function report(string $label, array $ids): void {
		foreach ($ids as $id) {
			$this->problems[] = $label . ': ' . $id;
		}
	}
}
