<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Checks the FQCN map and the size inventory against one tree.
 *
 * Map: paths, base names and destinations are unique, no destination collides with another file's name, each
 * destination stays under the PSR-4 prefix, each entry's file exists under one of its chain names (a `removed`
 * entry may be gone) and declares that name, and every PHP file of `lib/` belongs to an entry. Inventory: every
 * listed name is a map entry, every file above the limit is listed, no file is above its ceiling, and each
 * transitional ticket is well formed (and exists, when a tickets folder is given).
 */
final class MapCoverage {
	/** Shape of a ticket id. */
	private const TICKET = '/^RE-\d{2}[a-z]?$/';

	/** @var list<string> problems found, in order */
	private array $problems = [];

	/** @var array<string,int> how many `lib/` files are above the limit, per list (`final`, `transitional`) */
	private array $counts = ['final' => 0, 'transitional' => 0];

	/**
	 * @param array<string,string> $files files of the tree, path => content
	 * @param FqcnMap $map the map
	 * @param SizeInventory $inventory the size inventory
	 */
	public function __construct(
		private readonly array $files,
		private readonly FqcnMap $map,
		private readonly SizeInventory $inventory,
	) {
	}

	/**
	 * Runs every check.
	 *
	 * @param string|null $ticketsFolder folder holding one `<ticket-id>-<slug>` folder per ticket, or null to skip
	 * @return list<string> problems; empty when map and inventory match the tree
	 */
	public function check(?string $ticketsFolder = null): array {
		$owners = $this->checkMap();
		$this->checkInventory($owners, $ticketsFolder);
		return $this->problems;
	}

	/**
	 * Files above the limit found in the tree, per list.
	 *
	 * @return array<string,int> `final` and `transitional` counts
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * Checks the map entries and their coverage of `lib/`.
	 *
	 * @return array<string,string> path in the tree => base FQCN of the entry it belongs to
	 */
	private function checkMap(): array {
		$index = new SourceTreeIndex($this->files);
		$seen = [];
		$owners = [];
		foreach ($this->map->entries as $entry) {
			$steps = [$entry['fqcn']];
			foreach ($this->map->chainsOf($entry) as $chain) {
				$steps = array_merge($steps, $chain);
			}
			foreach (array_unique([$entry['path'], ...$steps]) as $name) {
				$key = strtolower($name);
				if (isset($seen[$key]) && $seen[$key] !== $entry['path']) {
					$this->problems[] = "{$entry['path']}: $name is also used by {$seen[$key]} (conflicting destination)";
				}
				$seen[$key] = $entry['path'];
			}
			foreach ($steps as $step) {
				if ($this->map->pathOf($step) === null) {
					$this->problems[] = "{$entry['path']}: $step is outside the PSR-4 prefix {$this->map->prefix}";
				}
			}
			if ($entry['ticket'] !== null && preg_match(self::TICKET, $entry['ticket']) !== 1) {
				$this->problems[] = "{$entry['path']}: malformed ticket {$entry['ticket']}";
			}
			if (($entry['target'] === FqcnMap::STAYS) !== ($entry['ticket'] === null)) {
				$this->problems[] = "{$entry['path']}: a file that stays has no ticket, every other file has one";
			}
			$found = null;
			foreach (array_unique($steps) as $step) {
				$path = (string)$this->map->pathOf($step);
				if (isset($this->files[$path]) && in_array(strtolower($step), array_map('strtolower', $index->declaredIn($path)), true)) {
					$found = $path;
				}
			}
			if ($found === null && $entry['target'] !== FqcnMap::REMOVED) {
				$this->problems[] = "{$entry['path']}: no file declares {$entry['fqcn']} or a later name of it";
			}
			if ($found !== null) {
				$owners[$found] = $entry['fqcn'];
			}
		}
		foreach (array_keys($this->files) as $path) {
			if (str_starts_with($path, $this->map->root) && str_ends_with($path, '.php') && !isset($owners[$path])) {
				$this->problems[] = "$path: not in the FQCN map";
			}
		}
		return $owners;
	}

	/**
	 * Checks the size inventory against the map and the line counts of the tree.
	 *
	 * @param array<string,string> $owners path => base FQCN, from {@see checkMap()}
	 * @param string|null $ticketsFolder folder of the tickets, or null to skip the existence check
	 */
	private function checkInventory(array $owners, ?string $ticketsFolder): void {
		$known = array_change_key_case(array_flip($owners));
		foreach ([...$this->inventory->final, ...$this->inventory->transitional] as $entry) {
			if (!isset($known[strtolower($entry['fqcn'])])) {
				$this->problems[] = "size inventory: {$entry['fqcn']} is not a file of the map in this tree";
			}
		}
		foreach ($owners as $path => $fqcn) {
			$lines = substr_count($this->files[$path], "\n");
			$ceiling = $this->inventory->ceilingOf($fqcn);
			if ($lines > $ceiling) {
				$this->problems[] = "$path: $lines lines, ceiling $ceiling";
			}
			foreach (['final' => $this->inventory->final, 'transitional' => $this->inventory->transitional] as $list => $entries) {
				if ($lines > $this->inventory->limit && isset($entries[strtolower($fqcn)])) {
					$this->counts[$list]++;
				}
			}
		}
		foreach ($this->inventory->tickets() as $ticket) {
			if (preg_match(self::TICKET, $ticket) !== 1) {
				$this->problems[] = "size inventory: malformed ticket $ticket";
			} elseif ($ticketsFolder !== null && glob(rtrim($ticketsFolder, '/') . '/' . strtolower($ticket) . '-*', GLOB_ONLYDIR) === []) {
				$this->problems[] = "size inventory: ticket $ticket does not exist in $ticketsFolder";
			}
		}
	}
}
