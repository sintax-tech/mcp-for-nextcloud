<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Proves that a move commit changes only mapped class names and actual paired file paths in test readers.
 *
 * Every changed PHP file is compared: each moved file with its destination (paired by the map, or by file name for
 * classes outside the map such as tests) and each caller with its own new version. Both sides go through
 * {@see TokenNormalizer}, the old one with the renames of the range applied; the streams must be identical. Then
 * every PHP file of the new tree is searched for references still naming an old class. A deleted file without a
 * destination, a new file, a non-PHP change outside paired test-path edits and a move the map does not plan all fail.
 */
final class MoveEquivalence {
	/** Non-PHP files a move commit may change: the map itself and the size inventory indexed by it. */
	public const ALLOWED_DATA = ['scripts/dev/fqcn-map.json', 'scripts/dev/size-inventory.json'];

	/** @var list<string> problems found, in order */
	private array $problems = [];

	/** @var array<string,string> old FQCN => new FQCN of every class moved in the range */
	private array $renames = [];

	/** @var array<string,string> old FQCN => new FQCN of the classes of `lib/` moved in the range */
	private array $libRenames = [];

	/** @var array<string,string> old path => new path of every moved PHP file */
	private array $pairs = [];

	private readonly SourceTreeIndex $beforeIndex;
	private readonly SourceTreeIndex $afterIndex;

	/**
	 * @param array<string,string> $before files of the old tree, path => content
	 * @param array<string,string> $after files of the new tree, path => content
	 * @param FqcnMap $map the map of the new tree
	 */
	public function __construct(
		private readonly array $before,
		private readonly array $after,
		private readonly FqcnMap $map,
	) {
		$this->beforeIndex = new SourceTreeIndex($before);
		$this->afterIndex = new SourceTreeIndex($after);
	}

	/**
	 * Runs every check.
	 *
	 * @param string|null $ticket when set, every map entry of this ticket must have moved in the range
	 * @return list<string> problems; empty when the range is equivalent
	 */
	public function check(?string $ticket = null): array {
		$deleted = array_keys(array_diff_key($this->before, $this->after));
		$added = array_keys(array_diff_key($this->after, $this->before));
		$modified = array_keys(array_filter(array_intersect_key($this->before, $this->after), fn (string $content, string $path): bool => $content !== $this->after[$path], ARRAY_FILTER_USE_BOTH));
		$php = static fn (array $paths): array => array_values(array_filter($paths, static fn (string $p): bool => str_ends_with($p, '.php')));
		$this->pair($php($deleted), $php($added));
		$this->collectRenames();
		$this->problems = [...$this->problems, ...BoundaryMoves::problems($this->before, $this->after, $this->pairs, $this->renames, $this->map)];
		$this->problems = [...$this->problems, ...PathRewrites::nonPhp($this->before, $this->after, $this->pairs, $this->renames)];
		$retired = array_filter($this->libRenames, fn (string $old): bool => !$this->afterIndex->declares($old), ARRAY_FILTER_USE_KEY);
		$this->problems = [...$this->problems, ...ClassRewrites::stale($this->after, $retired)];
		$targets = array_combine(array_values($this->pairs), array_values($this->pairs));
		$normalizeBefore = new TokenNormalizer($this->beforeIndex);
		$normalizeAfter = new TokenNormalizer($this->afterIndex);
		$compared = $this->pairs + array_combine($php($modified), $php($modified));
		$normalized = [];
		foreach ($compared as $old => $new) {
			$normalized[$new] = $normalizeAfter->normalize($this->after[$new], [], $new, $targets);
			$source = $normalizeBefore->normalize($this->before[$old], $this->renames, $old, $this->pairs);
			$difference = $source->firstDifference($normalized[$new]);
			if ($difference !== null) {
				[$oldLine, $oldToken, $newLine, $newToken] = $difference;
				$reason = str_starts_with($oldToken, 'P:') || str_starts_with($newToken, 'P:') ? ' (path not proved)' : '';
				$this->problems[] = "$new: not equivalent to $old: line $oldLine `$oldToken` became line $newLine `$newToken`$reason";
			}
			if (dirname($old) !== dirname($new) || $this->beforeIndex->declaredIn($old) !== $this->afterIndex->declaredIn($new)) {
				foreach (array_unique([...$source->located, ...$normalized[$new]->located], SORT_REGULAR) as [$line, $construct]) {
					$this->problems[] = "$new: line $line of $old uses $construct, whose value depends on the file's folder or namespace; the move changes them and the value cannot be proved the same (path not proved)";
				}
			}
		}
		$this->staleReferences($normalizeAfter, $normalized);
		if ($ticket !== null) {
			$this->omittedEntries($ticket);
		}
		return $this->problems;
	}

	/**
	 * Moved files found in the range.
	 *
	 * @return array<string,string> old path => new path
	 */
	public function pairs(): array {
		return $this->pairs;
	}

	/**
	 * Pairs each deleted file with its destination: first by the map, then by an unambiguous file name.
	 *
	 * @param list<string> $deleted deleted PHP paths
	 * @param list<string> $added added PHP paths
	 */
	private function pair(array $deleted, array $added): void {
		$free = array_fill_keys($added, true);
		$pending = [];
		foreach ($deleted as $old) {
			$class = $this->beforeIndex->declaredIn($old)[0] ?? null;
			$new = $class === null ? null : $this->mappedDestination($class, $free);
			if ($new === null) {
				$pending[] = $old;
				continue;
			}
			$this->pairs[$old] = $new;
			unset($free[$new]);
		}
		foreach ($pending as $old) {
			$candidates = array_values(array_filter(array_keys($free), static fn (string $p): bool => basename($p) === basename($old)));
			if (count($candidates) === 1) {
				$this->pairs[$old] = $candidates[0];
				unset($free[$candidates[0]]);
			} elseif ($candidates === []) {
				$this->problems[] = "$old: deleted without a destination (file omitted)";
			} else {
				$this->problems[] = "$old: several destinations with the same name: " . implode(', ', $candidates);
			}
		}
		foreach (array_keys($free) as $new) {
			$this->problems[] = "$new: new file in a move range";
		}
	}

	/**
	 * Finds the destination the map plans for a class among the added files.
	 *
	 * @param string $class fully qualified name before
	 * @param array<string,true> $free added paths not paired yet
	 * @return string|null the added path declaring a later step of the class's chain
	 */
	private function mappedDestination(string $class, array $free): ?string {
		foreach ($this->map->entries as $entry) {
			foreach ($this->map->chainsOf($entry) as $chain) {
				foreach ($chain as $step) {
					$path = $this->map->pathOf($step);
					if ($path !== null && isset($free[$path]) && $this->map->allows($class, $step)
						&& in_array(strtolower($step), array_map('strtolower', $this->afterIndex->declaredIn($path)), true)) {
						return $path;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Builds the renames of the range from the paired files and checks each one against the map.
	 */
	private function collectRenames(): void {
		foreach ($this->pairs as $old => $new) {
			$from = $this->beforeIndex->declaredIn($old);
			$to = $this->afterIndex->declaredIn($new);
			if (count($from) !== count($to)) {
				$this->problems[] = "$new: declares " . count($to) . " classes, $old declared " . count($from);
				continue;
			}
			foreach ($from as $k => $class) {
				if ($class === $to[$k]) {
					continue;
				}
				$inLib = $this->map->pathOf($class) !== null && str_starts_with($old, $this->map->root);
				$sameShortName = substr($class, (int)strrpos($class, '\\')) === substr($to[$k], (int)strrpos($to[$k], '\\'));
				if ($inLib ? !$this->map->allows($class, $to[$k]) : !$sameShortName && !$this->map->allows($class, $to[$k])) {
					$this->problems[] = "$new: $class became {$to[$k]}, which the FQCN map does not plan";
				}
				$this->renames[$class] = $to[$k];
				if ($inLib) {
					$this->libRenames[$class] = $to[$k];
				}
			}
		}
	}

	/**
	 * Reports every reference in the new tree that still names a class retired in the range (declared in the old
	 * tree, or renamed by it, and no longer declared in the new one), and every reference of a changed file to a
	 * planned name that no file declares. Unchanged files may name planned destinations (fixtures, documentation
	 * data) without being stale. Exact app/lib-relative literal path identities, including #/: inventory keys,
	 * are checked for retired files; unrecognized prefixes and composed basename references are not proved.
	 *
	 * @param TokenNormalizer $normalizer normalizer of the new tree
	 * @param array<string,NormalizedSource> $normalized files already normalized, by path
	 */
	private function staleReferences(TokenNormalizer $normalizer, array $normalized): void {
		$renamed = array_change_key_case($this->renames);
		$retiredNamespaces = [];
		foreach (array_keys($this->renames) as $class) {
			if (!$this->afterIndex->declares($class)) {
				$retiredNamespaces[strtolower(substr($class, 0, (int)strrpos($class, '\\')))] = true;
			}
		}
		foreach ($this->after as $path => $content) {
			if (!str_ends_with($path, '.php')) {
				continue;
			}
			$source = $normalized[$path] ?? $normalizer->normalize($content, [], $path);
			$this->problems = [...$this->problems, ...PathExpectations::problems($path, $content, $this->pairs)];
			foreach ($source->namespaces as [$line, $namespace]) {
				if (isset($retiredNamespaces[strtolower($namespace)])) {
					$this->problems[] = "$path:$line: __NAMESPACE__ in $namespace lost a class in this range; composed class reference not proved";
				}
			}
			foreach ($source->strings as [$value, $line]) {
				foreach (array_keys($this->pairs) as $moved) {
					if (PathIdentity::match($value, $moved) === null && PathIdentity::match($value, $this->pairs[$moved]) === null
						&& (PathIdentity::unproved($value, $moved) || PathIdentity::unproved($value, $this->pairs[$moved]))) {
						$this->problems[] = "$path:$line: path not proved: composed or basename-relative reference $value";
					}
					$match = PathIdentity::namesDestination($value, $moved, $this->pairs[$moved]) ? null : PathIdentity::match($value, $moved);
					if ($match !== null) {
						$this->problems[] = PathIdentity::proved($match)
							? "$path:$line: still refers to the file $moved, moved to {$this->pairs[$moved]} in this range"
							: "$path:$line: path not proved: unrecognized prefix in $value";
					}
					$destination = PathIdentity::match($value, $this->pairs[$moved]);
					if ($destination !== null && !PathIdentity::proved($destination)) {
						$this->problems[] = "$path:$line: path not proved: unrecognized prefix in $value";
					}
				}
			}
			foreach ($source->references as [$class, $line]) {
				// Only a name the old tree declared and the new one does not is stale; a planned destination that does
				// not exist yet, or a name that never was a class of the app, is not.
				if ($this->afterIndex->declares($class)) {
					continue;
				}
				if (isset($renamed[strtolower($class)]) || $this->beforeIndex->declares($class)) {
					$this->problems[] = "$path:$line: still refers to $class, which no longer exists under that name";
				} elseif (isset($normalized[$path]) && $this->map->knows($class)) {
					// A file this range changed now names a planned class that no file declares (its move was omitted).
					$this->problems[] = "$path:$line: refers to $class, which no file declares";
				}
			}
		}
	}

	/**
	 * Reports every map entry of the ticket that the range did not move.
	 *
	 * @param string $ticket ticket id, e.g. `RE-13`
	 */
	private function omittedEntries(string $ticket): void {
		foreach ($this->map->entries as $entry) {
			if ($entry['ticket'] !== $ticket || in_array($entry['target'], [FqcnMap::STAYS, FqcnMap::REMOVED], true)) {
				continue;
			}
			$path = $this->map->pathOf($entry['target']);
			if ($path === null || !in_array(strtolower($entry['target']), array_map('strtolower', $this->afterIndex->declaredIn($path)), true)) {
				$this->problems[] = "{$entry['path']}: $ticket plans {$entry['target']}, missing at its destination (file omitted)";
			}
		}
	}
}
