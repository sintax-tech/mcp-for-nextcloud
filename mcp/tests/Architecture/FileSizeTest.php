<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Inventory\{AppTree, SizeRules, TicketState};
use RuntimeException;

/** D6 frozen membership, live ceilings, portable expiration and source-only controls. */
final class FileSizeTest extends ArchitectureCase {
	private function problems(?array $live = null, ?array $files = null, ?TicketState $tickets = null): array {
		return SizeRules::problems($files === null ? self::tree() : self::tree()->withFiles($files), $live ?? AppTree::json(self::tree()->root . '/scripts/dev/size-inventory.json'), self::json('size-baseline'), self::json('source-size-baseline'), $tickets ?? self::tickets());
	}
	public function testAppSourceHasNoNewOversizedFileOrRaisedCeiling(): void {
		self::assertCount(21, self::json('size-baseline')['final']);
		self::assertCount(0, self::json('size-baseline')['transitional']);
		self::assertSame([], $this->problems());
		self::assertSame('lib/OAuth/Storage/OAuthStore.php', self::tree()->present('OCA\\Mcp\\OAuth\\OAuthStore'));
	}
	public function testNewFileBoundaryAndFinalNewlineAreEnforced(): void {
		$files = self::tree()->files; $files['src/new.js'] = str_repeat("// x\n", 300);
		self::assertSame([], $this->problems(files: $files));
		$files['src/new.js'] .= "// x\n";
		self::assertNotSame([], $this->problems(files: $files));
		$files['src/new.js'] = str_repeat("// x\n", 299) . '// x';
		self::assertNotSame([], $this->problems(files: $files));
	}
	public function testMembershipPromotionCeilingsAndTransientGrowthFail(): void {
		$live = AppTree::json(self::tree()->root . '/scripts/dev/size-inventory.json');
		self::assertSame([], SizeRules::problems(self::tree(), $live, self::json('size-baseline'), self::json('source-size-baseline'), self::tickets()));
		$source = array_key_first(array_filter(self::tree()->files, static fn (string $path): bool => str_starts_with($path, 'src/'), ARRAY_FILTER_USE_KEY));
		$paths = [$source => ['ceiling' => substr_count(self::tree()->files[$source], "\n"), 'expires' => 'RE-28']]; $paths[$source]['ceiling']++;
		self::assertNotSame([], SizeRules::problems(self::tree(), $live, self::json('size-baseline'), $paths, self::tickets()));
		// Exercise the historical ratchet locally even after every live exception is retired.
		$name = 'OCA\\Mcp\\Service\\Policy\\PolicyWriter';
		$row = ['ceiling' => 1485, 'expires' => 'RE-06d', 'steps' => [
			['ticket' => 'RE-06', 'ceiling' => 1300], ['ticket' => 'RE-06b', 'ceiling' => 900], ['ticket' => 'RE-06c', 'ceiling' => 500],
		]];
		$baseline = self::json('size-baseline');
		$baseline['transitional'] = [['nameParts' => explode('\\', $name), ...$row]];
		$live['transitional'] = [['fqcn' => $name, ...$row]];
		$tickets = new TicketState(self::tickets()->known, array_values(array_diff(self::tickets()->completed, ['RE-06d'])));
		$files = self::tree()->files; $path = self::tree()->present($name);
		$files[$path] = str_repeat("// x\n", 500);
		/** @return list<string> Violations against this test's historical baseline only. */
		$problems = static fn (array $inventory, array $sources): array => SizeRules::problems(self::tree()->withFiles($sources), $inventory, $baseline, self::json('source-size-baseline'), $tickets);
		self::assertSame([], $problems($live, $files));
		$raised = $live; $raised['final'][0]['ceiling']++;
		$added = $live; $added['final'][] = ['fqcn' => 'OCA\\Mcp\\Platform\\Numbers\\DecimalCounter', 'ceiling' => 500];
		$swapped = $live; $swapped['final'][0]['fqcn'] = 'OCA\\Mcp\\Platform\\Numbers\\DecimalCounter';
		$promoted = $live; $promoted['final'][] = array_shift($promoted['transitional']);
		foreach ($live['final'] as $index => $row) {
			if (str_ends_with($row['fqcn'], '\\GrantPolicy') || str_ends_with($row['fqcn'], '\\McpProtocol')) { $revived = $live; $revived['final'][$index]['ceiling'] = $row['ceiling'] + 1; self::assertNotSame([], $problems($revived, $files)); }
		}
		foreach ([$raised, $added, $swapped, $promoted] as $mutant) { self::assertNotSame([], $problems($mutant, $files)); }
		$files[$path] .= "// x\n";
		self::assertNotSame([], $problems($live, $files));
		foreach (['RE-05b', 'RE-999'] as $ticket) {
			$expired = $live; $expired['transitional'][0]['expires'] = $ticket;
			try { $problems($expired, $files); self::fail('Expired size exception accepted'); }
			catch (RuntimeException $error) { self::assertStringContainsString('expiration', $error->getMessage()); }
		}
	}
	public function testCompletedPolicyWriterStepLowersTheEffectiveCeiling(): void {
		$live = AppTree::json(self::tree()->root . '/scripts/dev/size-inventory.json');
		$name = 'OCA\\Mcp\\Service\\Policy\\PolicyWriter';
		$files = self::tree()->files; $path = self::tree()->present($name);
		// The real completed tree has no exception: the default 300-line ceiling applies.
		$files[$path] = str_repeat("// x\n", 300);
		self::assertSame([], $this->problems(files: $files));
		$files[$path] .= "// x\n";
		self::assertNotSame([], $this->problems(files: $files));
		// Fabricate the historical RE-06c state without reviving an expired live exception.
		$tickets = new TicketState(self::tickets()->known, array_values(array_diff(self::tickets()->completed, ['RE-06d'])));
		$row = ['ceiling' => 1485, 'expires' => 'RE-06d', 'steps' => [
			['ticket' => 'RE-06', 'ceiling' => 1300], ['ticket' => 'RE-06b', 'ceiling' => 900], ['ticket' => 'RE-06c', 'ceiling' => 500],
		]];
		$live['transitional'][] = ['fqcn' => $name, ...$row];
		$baseline = self::json('size-baseline');
		$baseline['transitional'][] = ['nameParts' => explode('\\', $name), ...$row];
		/** @param array<string, string> $sources Local source bytes. @return list<string> Violations of the fabricated RE-06c state only. */
		$problems = static fn (array $sources): array => SizeRules::problems(self::tree()->withFiles($sources), $live, $baseline, self::json('source-size-baseline'), $tickets);
		$files[$path] = str_repeat("// x\n", 500);
		self::assertSame([], $problems($files));
		$files[$path] .= "// x\n";
		self::assertNotSame([], $problems($files));
	}
}
