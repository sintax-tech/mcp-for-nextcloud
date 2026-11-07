<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Ci;

/** Verifies planned and executed test identities, including every named or numeric dataset, exactly once. */
final class PhpunitCoverage {
	/**
	 * Assigns the unfiltered official inventory to explicit profiles and rejects omissions, overlaps and empty profiles.
	 * @param string $work Disposable report directory containing the PHPUnit inventory XML.
	 * @param array<string,string> $filters Profile selectors.
	 * @return void
	 */
	public static function prepare(string $work, array $filters): void {
		$xml = self::xml($work . '/inventory.xml');
		$partitions = array_fill_keys(array_keys($filters), []);
		$all = [];
		$names = [];
		foreach ($xml->testCaseClass as $class) {
			foreach ($class->testCaseMethod as $method) {
				$base = (string)$class['name'] . '::' . $method['name'];
				$id = (string)$method['id'];
				$names[] = $base;
				$owners = array_keys(array_filter($filters, static fn (string $filter): bool => preg_match($filter, $base) === 1));
				if (count($owners) !== 1 || isset($all[$id])) { throw new \RuntimeException('Omitted or duplicated inventory identity: ' . $id); }
				$all[$id] = true;
				$partitions[$owners[0]][] = $id;
			}
		}
		PhpunitProfiles::assertExplicitInventory($names);
		foreach ($partitions as $name => $ids) {
			if ($ids === []) { throw new \RuntimeException('Empty PHPUnit partition: ' . $name); }
			echo $name . ': ' . count($ids) . " inventoried cases\n";
		}
		file_put_contents($work . '/inventory.json', json_encode($partitions, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	}

	/**
	 * Compares every profile's actual JUnit multiset to its inventory; any omission, duplicate, unexpected case or failure fails.
	 * @param string $work Disposable report directory.
	 * @return void
	 */
	public static function verify(string $work): void {
		$partitions = json_decode((string)file_get_contents($work . '/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
		$total = 0;
		$seen = [];
		foreach ($partitions as $profile => $expected) {
			$actual = [];
			foreach (self::xml($work . '/' . $profile . '.xml')->xpath('//testcase') as $case) {
				$name = (string)$case['name'];
				if (preg_match('/^(.*) with data set (?:"(.*)"|#(-?[0-9]+))$/s', $name, $match)) {
					$name = $match[1] . '#' . ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
				}
				$id = (string)$case['class'] . '::' . $name;
				if (isset($seen[$id])) { throw new \RuntimeException('Test executed twice: ' . $id); }
				if (isset($case->failure) || isset($case->error) || isset($case->skipped) || isset($case->incomplete)) { throw new \RuntimeException('Failing or unexecuted test: ' . $id); }
				$seen[$id] = true;
				$actual[] = $id;
			}
			sort($expected);
			sort($actual);
			if ($expected !== $actual) {
				throw new \RuntimeException($profile . ': inventory mismatch; missing=' . json_encode(array_values(array_diff($expected, $actual)))
					. '; unexpected=' . json_encode(array_values(array_diff($actual, $expected))));
			}
			$total += count($actual);
			echo $profile . ': ' . count($actual) . " cases executed exactly once\n";
		}
		$official = [];
		foreach (self::xml($work . '/inventory.xml')->xpath('//testCaseMethod') as $method) { $official[] = (string)$method['id']; }
		$executed = array_keys($seen);
		sort($official);
		sort($executed);
		if ($official !== $executed) { throw new \RuntimeException('Executed union differs from the unfiltered official inventory'); }
		echo "Complete official inventory verified: $total cases, no gaps or duplicates\n";
	}

	/**
	 * Loads complete XML; a missing, truncated or empty report is a failing gate.
	 * @param string $path Inventory or JUnit report path.
	 * @return \SimpleXMLElement Parsed document.
	 */
	private static function xml(string $path): \SimpleXMLElement {
		if (!is_file($path) || filesize($path) === 0) { throw new \RuntimeException('Missing or empty report: ' . $path); }
		$xml = simplexml_load_file($path);
		return $xml === false ? throw new \RuntimeException('Invalid XML: ' . $path) : $xml;
	}
}
