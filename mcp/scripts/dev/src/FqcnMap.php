<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

use RuntimeException;

/**
 * The per-file map of `lib/` from the 1.3.0 restructuring (`scripts/dev/fqcn-map.json`).
 *
 * Each entry is one file of the base: `path` and `fqcn` as in the base, `target` (the planned fully qualified
 * name, `stays` or `removed`), `owner` (layer or module), `ticket` (the ticket whose move commit performs it, null
 * for `stays`), optional `via` (intermediate names of a move in several hops) and optional `companions` (other
 * classes declared in the same file, which follow its namespace). A class may move from any name of its chain
 * `fqcn, via..., target` to a later one.
 */
final class FqcnMap {
	/** Target of a file that keeps its name. */
	public const STAYS = 'stays';

	/** Target of a file deleted by its ticket's split commit (never by a move commit). */
	public const REMOVED = 'removed';

	/** @var array<string,list<string>> lower-case name of any chain step => the whole chain */
	private array $chains = [];

	/**
	 * @param list<array{path:string,fqcn:string,target:string,owner:string,ticket:?string,via?:list<string>,companions?:list<string>}> $entries
	 * @param string $prefix PSR-4 namespace prefix, with trailing backslash
	 * @param string $root PSR-4 folder of the prefix, with trailing slash
	 */
	public function __construct(
		public readonly array $entries,
		public readonly string $prefix,
		public readonly string $root,
	) {
		foreach ($entries as $entry) {
			foreach ($this->chainsOf($entry) as $chain) {
				foreach ($chain as $step) {
					$this->chains[strtolower($step)] = $chain;
				}
			}
		}
	}

	/**
	 * Loads and checks the shape of a map document.
	 *
	 * @param string $json the document
	 * @return self the map
	 * @throws RuntimeException when the document is not a valid map
	 */
	public static function fromJson(string $json): self {
		$data = json_decode($json, true);
		if (!is_array($data) || !is_array($data['entries'] ?? null) || !is_array($data['psr4'] ?? null) || count($data['psr4']) !== 1) {
			throw new RuntimeException('fqcn map: expected {"psr4": {prefix: folder}, "entries": [...]}');
		}
		foreach ($data['entries'] as $n => $entry) {
			foreach (['path', 'fqcn', 'target', 'owner'] as $field) {
				if (!is_string($entry[$field] ?? null) || $entry[$field] === '') {
					throw new RuntimeException("fqcn map: entry $n has no $field");
				}
			}
			if (!array_key_exists('ticket', $entry) || !(is_string($entry['ticket']) || $entry['ticket'] === null)) {
				throw new RuntimeException("fqcn map: entry $n has no ticket (use null for stays)");
			}
		}
		return new self($data['entries'], (string)array_key_first($data['psr4']), (string)reset($data['psr4']));
	}

	/**
	 * Tells whether the map lets a class move from one name to another.
	 *
	 * @param string $from fully qualified name before
	 * @param string $to fully qualified name after
	 * @return bool true when both are steps of one chain and `to` comes later
	 */
	public function allows(string $from, string $to): bool {
		$chain = array_map('strtolower', $this->chains[strtolower($from)] ?? []);
		$start = array_search(strtolower($from), $chain, true);
		$end = array_search(strtolower($to), $chain, true);
		return $start !== false && $end !== false && $end > $start;
	}

	/**
	 * Tells whether a name is any step of a planned move.
	 *
	 * @param string $class fully qualified name
	 * @return bool true when the map moves the class through this name
	 */
	public function knows(string $class): bool {
		return isset($this->chains[strtolower($class)]);
	}

	/**
	 * The file a fully qualified name lives in under PSR-4.
	 *
	 * @param string $class fully qualified name
	 * @return string|null path relative to the app folder; null outside the prefix
	 */
	public function pathOf(string $class): ?string {
		if (!str_starts_with($class, $this->prefix)) {
			return null;
		}
		return $this->root . str_replace('\\', '/', substr($class, strlen($this->prefix))) . '.php';
	}

	/**
	 * Every move chain of an entry: the file's class and each companion, which keeps its short name.
	 *
	 * @param array{fqcn:string,target:string,via?:list<string>,companions?:list<string>} $entry one entry
	 * @return list<list<string>> chains; none for `stays` or `removed`
	 */
	public function chainsOf(array $entry): array {
		if (in_array($entry['target'], [self::STAYS, self::REMOVED], true)) {
			return [];
		}
		$main = [$entry['fqcn'], ...($entry['via'] ?? []), $entry['target']];
		$chains = [$main];
		foreach ($entry['companions'] ?? [] as $companion) {
			$short = substr($companion, (int)strrpos($companion, '\\'));
			$chains[] = array_map(static fn (string $step): string => substr($step, 0, (int)strrpos($step, '\\')) . $short, $main);
		}
		return $chains;
	}
}
