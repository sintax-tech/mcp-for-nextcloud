<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

use RuntimeException;

/**
 * The size inventory of decision D6 (`scripts/dev/size-inventory.json`): every `lib/` file above the line limit,
 * indexed by its base FQCN from the map so the entry follows the file when it is renamed.
 *
 * - `final`: legacy files that stay large after 1.3.0; `ceiling` is the size in the base and only goes down.
 * - `transitional`: files the plan splits; `ceiling` is the size in the base, `expires` the ticket that brings the
 *   file under the limit, and optional `steps` lower ceilings reached by earlier tickets (PolicyWriter).
 */
final class SizeInventory {
	/**
	 * @param int $limit line limit for every file not listed
	 * @param array<string,array{fqcn:string,ceiling:int}> $final lower-case FQCN => entry
	 * @param array<string,array{fqcn:string,ceiling:int,expires:string,steps?:list<array{ticket:string,ceiling:int}>}> $transitional lower-case FQCN => entry
	 */
	public function __construct(
		public readonly int $limit,
		public readonly array $final,
		public readonly array $transitional,
	) {
	}

	/**
	 * Loads and checks the shape of an inventory document.
	 *
	 * @param string $json the document
	 * @return self the inventory
	 * @throws RuntimeException when the document is not a valid inventory or lists a file twice
	 */
	public static function fromJson(string $json): self {
		$data = json_decode($json, true);
		if (!is_array($data) || !is_int($data['limit'] ?? null) || !is_array($data['final'] ?? null) || !is_array($data['transitional'] ?? null)) {
			throw new RuntimeException('size inventory: expected {"limit": int, "final": [...], "transitional": [...]}');
		}
		$lists = ['final' => [], 'transitional' => []];
		foreach ($lists as $name => $unused) {
			foreach ($data[$name] as $n => $entry) {
				if (!is_string($entry['fqcn'] ?? null) || !is_int($entry['ceiling'] ?? null)) {
					throw new RuntimeException("size inventory: $name entry $n needs fqcn and ceiling");
				}
				if ($name === 'transitional' && !is_string($entry['expires'] ?? null)) {
					throw new RuntimeException("size inventory: transitional entry $n needs expires");
				}
				$key = strtolower($entry['fqcn']);
				if (isset($lists['final'][$key]) || isset($lists['transitional'][$key])) {
					throw new RuntimeException("size inventory: {$entry['fqcn']} is listed twice");
				}
				$lists[$name][$key] = $entry;
			}
		}
		return new self($data['limit'], $lists['final'], $lists['transitional']);
	}

	/**
	 * Highest number of lines a file may have.
	 *
	 * @param string $fqcn base FQCN of the file (map `fqcn`)
	 * @return int the entry's ceiling, or the limit for a file not listed
	 */
	public function ceilingOf(string $fqcn): int {
		$key = strtolower($fqcn);
		return $this->final[$key]['ceiling'] ?? $this->transitional[$key]['ceiling'] ?? $this->limit;
	}

	/**
	 * Every ticket the transitional list names, as expiry or step.
	 *
	 * @return list<string> ticket ids, without duplicates
	 */
	public function tickets(): array {
		$tickets = [];
		foreach ($this->transitional as $entry) {
			$tickets[] = $entry['expires'];
			foreach ($entry['steps'] ?? [] as $step) {
				$tickets[] = $step['ticket'];
			}
		}
		return array_values(array_unique($tickets));
	}
}
