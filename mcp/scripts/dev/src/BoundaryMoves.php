<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Scripts\Dev;

use OCA\Mcp\Tests\Architecture\Dependencies\BoundaryRules;
use OCA\Mcp\Tests\Architecture\Inventory\AppTree;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/tests/Architecture/Inventory/AppTree.php';
require_once dirname(__DIR__, 3) . '/tests/Architecture/Dependencies/ClassReferences.php';
require_once dirname(__DIR__, 3) . '/tests/Architecture/Dependencies/DynamicReferences.php';
require_once dirname(__DIR__, 3) . '/tests/Architecture/Dependencies/BoundaryRules.php';

/** Retargets the exact boundary ledger using proved moves and the same evaluator as the architecture gate. */
final class BoundaryMoves {
	/** The only structured non-PHP document governed here; never added to ALLOWED_DATA. */
	public const PATH = 'tests/Architecture/boundary-exceptions.json';

	/** @param string $path App-relative data path. @return bool This document has a stricter structural comparison. */
	public static function handles(string $path): bool { return $path === self::PATH; }

	/** @param array<string,string> $before Old revision. @param array<string,string> $after New revision. @param array<string,string> $pairs Proved file pairs. @param array<string,string> $renames Proved class renames. @param FqcnMap $map Map of the new revision. @return list<string> Strict ledger violations. */
	public static function problems(array $before, array $after, array $pairs, array $renames, FqcnMap $map): array {
		if (!isset($before[self::PATH]) && !isset($after[self::PATH])) { return []; }
		try {
			if (!isset($before[self::PATH], $after[self::PATH])) { throw new RuntimeException('Boundary ledger cannot appear or disappear in a move'); }
			$expected = json_decode(self::retarget($before, $after, $pairs, $renames, $map), true, 512, JSON_THROW_ON_ERROR);
			$new = json_decode($after[self::PATH], true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($new['exceptions'] ?? null)) { throw new RuntimeException('Malformed boundary ledger'); }
			$expectedRows = $expected['exceptions']; $newRows = $new['exceptions'];
			foreach ($newRows as $row) { if (isset($pairs[$row['source']]) && $row['source'] !== $pairs[$row['source']]) { throw new RuntimeException('still refers to the file ' . $row['source'] . ', moved to ' . $pairs[$row['source']]); } } unset($expected['exceptions'], $new['exceptions']);
			if ($expected !== $new || self::canonical($expectedRows) !== self::canonical($newRows)) { throw new RuntimeException('Boundary exceptions changed beyond proved source/target/line moves or vanished violations'); }
			return [];
		} catch (\Throwable $error) { return [self::PATH . ': ' . $error->getMessage()]; }
	}

	/** Computes the sole allowed next ledger; callers still have to pass the comparator against their authored data.
	 * @param array<string,string> $before Old revision. @param array<string,string> $after New revision. @param array<string,string> $pairs Proved pairs. @param array<string,string> $renames Proved names. @param FqcnMap $map New map. @return string Expected JSON. @throws RuntimeException Missing/unproved source, old entry or line mapping.
	 */
	public static function retarget(array $before, array $after, array $pairs, array $renames, FqcnMap $map): string {
		$document = json_decode($before[self::PATH], true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($document['exceptions'] ?? null)) { throw new RuntimeException('Malformed boundary ledger'); }
		$oldRows = $document['exceptions'];
		if (!array_filter($oldRows, static fn ($row) => ($before[$row['source']] ?? null) !== ($after[$row['source']] ?? null) || isset($pairs[$row['source']]) || isset($renames[$row['target']]))) { return $before[self::PATH]; }

		$oldIndex = AppTree::fromSources($before, $map);
		$newIndex = AppTree::fromSources($after, $map);
		$oldEdges = self::groups(BoundaryRules::violations($oldIndex));
		$newEdges = self::groups(BoundaryRules::violations($newIndex));
		$expected = [];
		foreach ($oldRows as $row) {
			$from = $row['source']; $to = $pairs[$from] ?? $from;
			if ($to !== $from) {
				$a = (new SourceTreeIndex([$from => $before[$from] ?? '']))->declaredIn($from)[0] ?? '';
				$b = (new SourceTreeIndex([$to => $after[$to] ?? '']))->declaredIn($to)[0] ?? '';
				if (!$map->allows($a, $b) || $map->pathOf($b) !== $to) { throw new RuntimeException('Boundary source is not a mapped move: ' . $to); }
			}
			$beforeKey = self::key($row);
			$beforeLines = $oldEdges[$beforeKey] ?? [];
			$position = array_search($row['line'], $beforeLines, true);
			if ($position === false) { throw new RuntimeException('Boundary entry was not observed before the move: ' . $beforeKey); }
			$row['source'] = $to;
			$row['target'] = $renames[$row['target']] ?? $row['target'];
			$afterLines = $newEdges[self::key($row)] ?? [];
			// Integration or a lower-layer destination can remove the violation while keeping the import.
			if ($afterLines === []) { continue; }
			if (count($beforeLines) !== count($afterLines)) { throw new RuntimeException('Ambiguous boundary line mapping: ' . $beforeKey); }
			$row['line'] = $afterLines[$position];
			$expected[] = $row;
		}
		$document['exceptions'] = $expected;
		return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	/** @param list<array<string,mixed>> $edges Evaluator output. @return array<string,list<int>> Ordered line sites per logical edge. */
	private static function groups(array $edges): array {
		$groups = [];
		foreach ($edges as $edge) { $groups[self::key($edge)][] = $edge['line']; }
		foreach ($groups as &$lines) { sort($lines); } unset($lines);
		return $groups;
	}

	/** @param array<string,mixed> $row One exact edge. @return string Logical identity before/after mapped substitution. */
	private static function key(array $row): string { return $row['rule'] . '|' . $row['source'] . '|' . $row['target']; }

	/** @param list<array<string,mixed>> $rows Full exception records. @return list<string> Order-independent records; no fields discarded. */
	private static function canonical(array $rows): array {
		$result = [];
		foreach ($rows as $row) { ksort($row); $result[] = json_encode($row, JSON_THROW_ON_ERROR); }
		sort($result);
		return $result;
	}
}
