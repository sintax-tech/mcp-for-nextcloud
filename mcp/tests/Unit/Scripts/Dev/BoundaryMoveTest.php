<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\{BoundaryMoves, FqcnMap, MoveEquivalence};
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** The real comparator permits only proved ledger maintenance, using the architecture gate's evaluator. */
final class BoundaryMoveTest extends TestCase {
	/** @param bool $integration Move to Integration rather than Reading. @param bool $shift Add one whitespace line. @return array{array<string,string>,array<string,string>,FqcnMap} Paired revisions and map. */
	public static function fixture(bool $integration, bool $shift = false): array {
		$old = implode('\\', ['OCA', 'Mcp', 'Tools', 'Notes', 'ArchitectureNativeGateway']);
		$new = implode('\\', ['OCA', 'Mcp', 'Modules', 'Notes', $integration ? 'Integration' : 'Reading', 'ArchitectureNativeGateway']);
		$from = 'lib/' . str_replace('\\', '/', substr($old, 8)) . '.php';
		$to = 'lib/' . str_replace('\\', '/', substr($new, 8)) . '.php';
		$map = new FqcnMap([['fqcn' => $old, 'target' => $new, 'path' => $from, 'owner' => 'Modules\\Notes', 'ticket' => 'RE-13']], 'OCA\\Mcp\\', 'lib/');
		$source = "<?php\nnamespace OCA\\Mcp\\Tools\\Notes;\nuse OCA\\Notes\\Service\\NotesService;\nclass ArchitectureNativeGateway {}\n";
		$row = ['rule' => 'native-app', 'source' => $from, 'target' => 'OCA\\Notes\\Service\\NotesService', 'line' => 3, 'reason' => 'Move the native dependency behind Integration.', 'expires' => 'RE-13'];
		$snapshot = json_encode(['final' => [['nameParts' => explode('\\', $old), 'ceiling' => 400]]]);
		$before = [$from => $source, BoundaryMoves::PATH => self::document([$row]), 'tests/Architecture/size-baseline.json' => $snapshot];
		$after = ['tests/Architecture/size-baseline.json' => $snapshot, $to => str_replace('namespace OCA\\Mcp\\Tools\\Notes;', 'namespace ' . substr($new, 0, strrpos($new, '\\')) . ';', $source)];
		if ($shift) { $after[$to] = str_replace("<?php\n", "<?php\n\n", $after[$to]); }
		$row['source'] = $to; $row['line'] += $shift ? 1 : 0;
		$after[BoundaryMoves::PATH] = self::document($integration ? [] : [$row]);
		return [$before, $after, $map];
	}

	/** @param array<mixed> $rows Exception records. @return string JSON source. */
	private static function document(array $rows): string { return json_encode(['version' => 1, 'exceptions' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; }

	/** @return void The import remains, but Integration eliminates the violation and requires removing its exception. */
	public function testMappedIntegrationMoveRemovesOnlyTheVanishedViolation(): void {
		[$before, $after, $map] = self::fixture(true);
		$move = new MoveEquivalence($before, $after, $map);
		self::assertSame([], $move->check('RE-13'));
		self::assertCount(1, $move->pairs());
	}

	/** @return void A retained violation retargets its exact source and line, without changing metadata. */
	public function testMappedSourceAndWhitespaceLineShiftAreProved(): void {
		[$before, $after, $map] = self::fixture(false, true);
		self::assertSame([], (new MoveEquivalence($before, $after, $map))->check('RE-13'));
	}

	/** @return void Added exception, changed metadata, persistent-edge removal and unproved sources all fail. */
	public function testEveryUnapprovedLedgerMutationFailsTheRealComparator(): void {
		[$before, $after, $map] = self::fixture(false);
		$document = json_decode($after[BoundaryMoves::PATH], true);
		$added = $document; $added['exceptions'][] = $added['exceptions'][0];
		$expiry = $document; $expiry['exceptions'][0]['expires'] = 'RE-14';
		$reason = $document; $reason['exceptions'][0]['reason'] = 'New permission';
		$removed = $document; $removed['exceptions'] = [];
		$source = $document; $source['exceptions'][0]['source'] = 'lib/Modules/Notes/Elsewhere.php';
		$line = $document; $line['exceptions'][0]['line']++;
		foreach ([$added, $expiry, $reason, $removed, $source, $line] as $mutant) {
			$changed = $after; $changed[BoundaryMoves::PATH] = json_encode($mutant);
			$problems = (new MoveEquivalence($before, $changed, $map))->check('RE-13');
			self::assertNotSame([], $problems);
			self::assertStringContainsString(BoundaryMoves::PATH, implode("\n", $problems));
		}
	}
}
