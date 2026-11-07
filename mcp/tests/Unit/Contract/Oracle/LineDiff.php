<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

/**
 * Line diff of two texts in the unified style (`-` golden line number, `+` actual line number, context around each
 * change, removals before additions), so a failing
 * golden says which lines moved instead of printing two walls of JSON.
 *
 * The common prefix and suffix are cut first; only the differing middle goes through the longest common subsequence,
 * and a middle larger than {@see self::MAX_CELLS} is shown whole instead, to keep the comparison bounded.
 */
final class LineDiff {
	/** Lines of unchanged context printed around each change. */
	private const CONTEXT = 3;
	/** Largest LCS table (lines × lines) computed; beyond it the differing middle is printed without alignment. */
	private const MAX_CELLS = 4_000_000;
	/** Lines printed at most, so a regenerated-from-scratch golden does not flood the output. */
	private const MAX_LINES = 400;

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * @param string $expected text of the golden file
	 * @param string $actual text produced now
	 * @return string unified-style diff; '' when both are equal
	 */
	public static function render(string $expected, string $actual): string {
		if ($expected === $actual) {
			return '';
		}
		$old = explode("\n", $expected);
		$new = explode("\n", $actual);
		$start = 0;
		while ($start < count($old) && $start < count($new) && $old[$start] === $new[$start]) {
			$start++;
		}
		$endOld = count($old);
		$endNew = count($new);
		while ($endOld > $start && $endNew > $start && $old[$endOld - 1] === $new[$endNew - 1]) {
			$endOld--;
			$endNew--;
		}
		$ops = self::align(array_slice($old, $start, $endOld - $start), array_slice($new, $start, $endNew - $start));
		$lines = [];
		for ($i = max(0, $start - self::CONTEXT); $i < $start; $i++) {
			$lines[] = '  ' . ($i + 1) . ': ' . $old[$i];
		}
		$oldLine = $start;
		$newLine = $start;
		foreach ($ops as [$kind, $text]) {
			if ($kind === '~') {
				$oldLine += (int)$text;
				$newLine += (int)$text;
				$lines[] = '  … ' . $text . ' unchanged lines';
			} elseif ($kind === '=') {
				$lines[] = '  ' . ++$oldLine . ': ' . $text;
				$newLine++;
			} elseif ($kind === '-') {
				$lines[] = '- ' . ++$oldLine . ': ' . $text;
			} else {
				$lines[] = '+ ' . ++$newLine . ': ' . $text;
			}
		}
		for ($i = $endOld; $i < min(count($old), $endOld + self::CONTEXT); $i++) {
			$lines[] = '  ' . ($i + 1) . ': ' . $old[$i];
		}
		if (count($lines) > self::MAX_LINES) {
			$lines = array_merge(array_slice($lines, 0, self::MAX_LINES), ['… ' . (count($lines) - self::MAX_LINES) . ' more lines']);
		}
		return "--- golden\n+++ actual\n" . implode("\n", $lines);
	}

	/**
	 * Aligns the differing middle by its longest common subsequence; unchanged lines far from any change collapse.
	 *
	 * @param list<string> $old middle lines of the golden
	 * @param list<string> $new middle lines produced now
	 * @return list<array{0:string, 1:string}> operations: '=' kept, '-' only in the golden, '+' only now, '~' a run of
	 *     unchanged lines collapsed (its length as text)
	 */
	private static function align(array $old, array $new): array {
		$n = count($old);
		$m = count($new);
		if ($n * $m > self::MAX_CELLS) {
			return array_merge(
				array_map(static fn (string $line): array => ['-', $line], $old),
				array_map(static fn (string $line): array => ['+', $line], $new),
			);
		}
		$table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--) {
			for ($j = $m - 1; $j >= 0; $j--) {
				$table[$i][$j] = $old[$i] === $new[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
			}
		}
		$ops = [];
		$i = 0;
		$j = 0;
		while ($i < $n || $j < $m) {
			if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
				$ops[] = ['=', $old[$i++]];
				$j++;
			} elseif ($j < $m && ($i === $n || $table[$i][$j + 1] > $table[$i + 1][$j])) {
				$ops[] = ['+', $new[$j++]];
			} else {
				$ops[] = ['-', $old[$i++]];
			}
		}
		return self::collapse($ops);
	}

	/**
	 * Keeps only the context lines near a change inside the middle.
	 *
	 * @param list<array{0:string, 1:string}> $ops aligned operations
	 * @return list<array{0:string, 1:string}> the same operations, unchanged runs far from a change reduced to '~'
	 */
	private static function collapse(array $ops): array {
		$near = [];
		foreach ($ops as $index => [$kind]) {
			if ($kind !== '=') {
				for ($k = max(0, $index - self::CONTEXT); $k <= min(count($ops) - 1, $index + self::CONTEXT); $k++) {
					$near[$k] = true;
				}
			}
		}
		$kept = [];
		$skipped = 0;
		foreach ($ops as $index => $op) {
			if ($op[0] !== '=' || isset($near[$index])) {
				if ($skipped > 0) {
					$kept[] = ['~', (string)$skipped];
					$skipped = 0;
				}
				$kept[] = $op;
			} else {
				$skipped++;
			}
		}
		if ($skipped > 0) {
			$kept[] = ['~', (string)$skipped];
		}
		return $kept;
	}
}
