<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MapCoverage;
use OCA\Mcp\Scripts\Dev\SizeInventory;
use OCA\Mcp\Scripts\Dev\SourceTrees;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Covers scripts/dev/map_check.php: the FQCN map covers every file of `lib/` without conflicting destinations,
 * and the size inventory lists every file above the limit, each under its ceiling.
 *
 * The last test runs the check on the real map, inventory and `lib/`, so a later ticket that moves or grows a file
 * without updating them fails here.
 */
final class MapCoverageTest extends TestCase {
	/** A consistent map and inventory pass and count the large files per list. */
	public function testAConsistentMapPasses(): void {
		$coverage = new MapCoverage(self::tree(), self::map(), self::inventory());
		$this->assertSame([], $coverage->check());
		$this->assertSame(['final' => 1, 'transitional' => 1], $coverage->counts());
	}

	/** A file of lib/ outside the map fails. */
	public function testAFileOutsideTheMapFails(): void {
		$tree = self::tree() + ['lib/Extra.php' => "<?php\nnamespace App;\nclass Extra {}\n"];
		$this->assertSame(['lib/Extra.php: not in the FQCN map'], (new MapCoverage($tree, self::map(), self::inventory()))->check());
	}

	/** Two entries planning the same destination, or a destination taken by a file that stays, conflict. */
	public function testConflictingDestinationsFail(): void {
		$map = self::map([2 => ['target' => 'App\Mod\Big', 'ticket' => 'RE-13']]);
		$problems = (new MapCoverage(self::tree(), $map, self::inventory()))->check();
		$this->assertContains('lib/Large.php: App\Mod\Big is also used by lib/Big.php (conflicting destination)', $problems);
	}

	/** A file above the limit that no list names, or above its ceiling, fails. */
	public function testLargeFilesOutsideTheInventoryOrAboveTheCeilingFail(): void {
		$tree = self::tree();
		$tree['lib/Small.php'] .= str_repeat("\n", 300);
		$tree['lib/Big.php'] .= "\n";
		$problems = (new MapCoverage($tree, self::map(), self::inventory()))->check();
		$this->assertContains('lib/Small.php: 303 lines, ceiling 300', $problems);
		$this->assertContains('lib/Big.php: 352 lines, ceiling 351', $problems);
	}

	/** A transitional entry must name a ticket that exists in the tickets folder. */
	public function testATransitionalTicketMustExist(): void {
		$folder = sys_get_temp_dir() . '/mcp-map-check-' . bin2hex(random_bytes(6));
		mkdir("$folder/re-13-module-notes", 0777, true);
		try {
			$this->assertSame([], (new MapCoverage(self::tree(), self::map(), self::inventory()))->check($folder));
			$inventory = SizeInventory::fromJson(str_replace('RE-13', 'RE-99', self::inventoryJson()));
			$this->assertSame(["size inventory: ticket RE-99 does not exist in $folder"], (new MapCoverage(self::tree(), self::map(), $inventory))->check($folder));
		} finally {
			// Only the folders this test created.
			rmdir("$folder/re-13-module-notes");
			rmdir($folder);
		}
	}

	/** A file listed twice, or in both lists, is refused when the inventory is loaded. */
	public function testAFileListedTwiceIsRefused(): void {
		$this->expectException(RuntimeException::class);
		SizeInventory::fromJson('{"limit": 300, "final": [{"fqcn": "App\\\\Big", "ceiling": 351}], "transitional": [{"fqcn": "App\\\\Big", "ceiling": 351, "expires": "RE-13"}]}');
	}

	/** The real map and inventory match lib/ of this tree: every file mapped, every large file listed. */
	public function testTheRepositoryMapAndInventoryMatchLib(): void {
		$dev = __DIR__ . '/../../../../scripts/dev';
		$map = FqcnMap::fromJson((string)file_get_contents("$dev/fqcn-map.json"));
		$inventory = SizeInventory::fromJson((string)file_get_contents("$dev/size-inventory.json"));
		$coverage = new MapCoverage(SourceTrees::fromDirectory(__DIR__ . '/../../../..', ['lib']), $map, $inventory);
		$this->assertSame([], $coverage->check());
		$this->assertSame(['final' => count($inventory->final), 'transitional' => count($inventory->transitional)], $coverage->counts());
	}

	/**
	 * Fixture tree: a small file, a final large one (351 lines) and a transitional one (310 lines).
	 *
	 * @return array<string,string> path => content
	 */
	private static function tree(): array {
		return [
			'lib/Small.php' => "<?php\nnamespace App;\nclass Small {}\n",
			'lib/Big.php' => "<?php\nnamespace App;\nclass Big {}\n" . str_repeat("\n", 348),
			'lib/Large.php' => "<?php\nnamespace App;\nclass Large {}\n" . str_repeat("\n", 307),
		];
	}

	/**
	 * Fixture map, with optional overrides per entry index.
	 *
	 * @param array<int,array<string,mixed>> $overrides entry index => fields to replace
	 * @return FqcnMap the map
	 */
	private static function map(array $overrides = []): FqcnMap {
		$entries = [
			['path' => 'lib/Small.php', 'fqcn' => 'App\Small', 'target' => 'stays', 'owner' => 'x', 'ticket' => null],
			['path' => 'lib/Big.php', 'fqcn' => 'App\Big', 'target' => 'App\Mod\Big', 'owner' => 'x', 'ticket' => 'RE-13'],
			['path' => 'lib/Large.php', 'fqcn' => 'App\Large', 'target' => 'App\Mod\Large', 'owner' => 'x', 'ticket' => 'RE-13'],
		];
		foreach ($overrides as $n => $fields) {
			$entries[$n] = $fields + $entries[$n];
		}
		return new FqcnMap($entries, 'App\\', 'lib/');
	}

	/**
	 * Fixture inventory document.
	 *
	 * @return string JSON
	 */
	private static function inventoryJson(): string {
		return '{"limit": 300, "final": [{"fqcn": "App\\\\Big", "ceiling": 351}], "transitional": [{"fqcn": "App\\\\Large", "ceiling": 310, "expires": "RE-13"}]}';
	}

	/**
	 * Fixture inventory.
	 *
	 * @return SizeInventory the inventory
	 */
	private static function inventory(): SizeInventory {
		return SizeInventory::fromJson(self::inventoryJson());
	}
}
