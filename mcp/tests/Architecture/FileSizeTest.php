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
		self::assertCount(27, self::json('size-baseline')['final']);
		self::assertCount(18, self::json('size-baseline')['transitional']);
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
		$raised = $live; $raised['final'][0]['ceiling']++;
		$added = $live; $added['final'][] = ['fqcn' => 'OCA\\Mcp\\Platform\\Numbers\\DecimalCounter', 'ceiling' => 500];
		$swapped = $live; $swapped['final'][0]['fqcn'] = 'OCA\\Mcp\\Platform\\Numbers\\DecimalCounter';
		$promoted = $live; $promoted['final'][] = array_shift($promoted['transitional']);
		foreach ($live['final'] as $index => $row) {
			if (str_ends_with($row['fqcn'], '\\GrantPolicy') || str_ends_with($row['fqcn'], '\\McpProtocol')) { $revived = $live; $revived['final'][$index]['ceiling'] = $row['ceiling'] + 1; self::assertNotSame([], $this->problems($revived)); }
		}
		$paths = self::json('source-size-baseline'); $paths[array_key_first($paths)]['ceiling']++;
		self::assertNotSame([], SizeRules::problems(self::tree(), $live, self::json('size-baseline'), $paths, self::tickets()));
		foreach ([$raised, $added, $swapped, $promoted] as $mutant) { self::assertNotSame([], $this->problems($mutant)); }
		$files = self::tree()->files; $path = self::tree()->present($live['transitional'][0]['fqcn']);
		$files[$path] = str_repeat("// x\n", $live['transitional'][0]['ceiling'] + 1);
		self::assertNotSame([], $this->problems(files: $files));
		foreach (['RE-05b', 'RE-999'] as $ticket) {
			$expired = $live; $expired['transitional'][0]['expires'] = $ticket;
			try { $this->problems($expired); self::fail('Expired size exception accepted'); }
			catch (RuntimeException $error) { self::assertStringContainsString('expiration', $error->getMessage()); }
		}
	}
	public function testCompletedPolicyWriterStepLowersTheEffectiveCeiling(): void {
		$tickets = new TicketState(self::tickets()->known, [...self::tickets()->completed, 'RE-06']);
		$files = self::tree()->files; $path = self::tree()->present('OCA\\Mcp\\Service\\Policy\\PolicyWriter');
		$files[$path] = str_repeat("// x\n", 1300);
		self::assertSame([], $this->problems(files: $files, tickets: $tickets));
		$files[$path] .= "// x\n";
		self::assertNotSame([], $this->problems(files: $files, tickets: $tickets));
	}
}
