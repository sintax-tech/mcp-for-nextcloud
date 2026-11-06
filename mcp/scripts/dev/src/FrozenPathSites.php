<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Consumes only individually sealed calls during the exact ticket/pair transition; otherwise entirely inert. */
final class FrozenPathSites {
	/** Historical proof data is not an allowed change in a move range. */
	public const PATH = 'scripts/dev/frozen-path-sites.json';
	/** @var array<string,bool> Exact physical side plus full occurrence identity, consumed at most once. */
	private array $allowed = [];
	/** @var list<string> Certificate errors and missing consumptions. */
	private array $errors = [];
	/** @var array<string,string> Physical source to historical map identity. */
	private array $identities = [];

	/** @param array<string,string> $before Old tree. @param array<string,string> $after New tree. @param array<string,string> $pairs Actual pairs. @param array<string,string> $renames Actual renames. @param FqcnMap $map Planned transitions. @param SourceTreeIndex $oldIndex Old declarations. @param SourceTreeIndex $newIndex New declarations. @param string|null $ticket Requested ticket. @param list<array<string,mixed>> $certificates Trusted descriptors, not supplied by the certificate JSON. */
	public function __construct(array $before, array $after, array $pairs, array $renames, FqcnMap $map, SourceTreeIndex $oldIndex, SourceTreeIndex $newIndex, ?string $ticket, array $certificates) {
		foreach ($certificates as $descriptor) {
			// Activation MUST precede even looking up the JSON: no later ticket inherits a production freeze.
			if ($ticket !== $descriptor['ticket'] || FrozenProduction::pairsHash($pairs) !== $descriptor['pairs_sha256']) { continue; }
			try { $this->load($descriptor, $before, $after, $pairs, $renames, $map, $oldIndex, $newIndex); }
			catch (\Throwable $error) { $this->allowed = []; $this->errors[] = self::PATH . ': ' . $error->getMessage(); }
		}
	}

	/** @param array<string,mixed> $trusted Immutable ticket/pairs/seal descriptor. @param array<string,string> $before Old tree. @param array<string,string> $after New tree. @param array<string,string> $pairs Exact pairs. @param array<string,string> $renames Exact class renames. @param FqcnMap $map Map. @param SourceTreeIndex $oldIndex Old index. @param SourceTreeIndex $newIndex New index. @return void */
	private function load(array $trusted, array $before, array $after, array $pairs, array $renames, FqcnMap $map, SourceTreeIndex $oldIndex, SourceTreeIndex $newIndex): void {
		$path = $trusted['path'];
		if (!isset($before[$path], $after[$path]) || $before[$path] !== $after[$path] || hash('sha256', $before[$path]) !== $trusted['seal']) { throw new \RuntimeException('Frozen manifest absent, changed or outside reviewed seal'); }
		$data = json_decode($before[$path], true, 512, JSON_THROW_ON_ERROR);
		if (($data['version'] ?? null) !== 2 || ($data['ticket'] ?? null) !== $trusted['ticket'] || !is_array($data['sites'] ?? null) || count($data['sites']) !== $trusted['sites']
			|| ($data['base_commit'] ?? null) !== $trusted['base_commit'] || ($data['base_tree'] ?? null) !== $trusted['base_tree']) { throw new \RuntimeException('Invalid frozen certificate anchor/schema'); }
		$targets = array_combine(array_values($pairs), array_values($pairs)); $reverse = array_flip($pairs);
		foreach ([[$before, $oldIndex, $renames, $pairs, []], [$after, $newIndex, [], $targets, $reverse]] as $side => [$files, $index, $names, $paths, $identities]) {
			if (FrozenProduction::inventory($files, $index, $names, $paths, $identities) !== ($data['production'] ?? null)) { throw new \RuntimeException('Frozen production inventory/hash differs (caller/origin changed)'); }
			foreach ($data['sites'] as $site) {
				$identity = $site['map_path']; $physical = $side === 0 ? $identity : ($pairs[$identity] ?? null);
				if (!isset($pairs[$identity], $files[$physical]) || $map->pathOf($site['target']) !== $pairs[$identity]) { throw new \RuntimeException('Frozen site is not its mapped move'); }
				foreach (token_get_all($files[$physical], TOKEN_PARSE) as $token) {
					if (is_array($token) && in_array($token[0], [T_DIR, T_FILE, T_NS_C], true)) { throw new \RuntimeException('Magic location in frozen path site source'); }
				}
				FrozenPathCall::locate($site, $files[$physical], $index);
				$source = (new TokenNormalizer($index))->normalize($files[$physical], $names, $physical, $paths);
				$matches = [];
				foreach ($source->located as $diagnostic) {
					if ($diagnostic[1] !== $site['callable'] . ' path not proved' || !isset($diagnostic[2])) { continue; }
					$call = FrozenPathOccurrence::at($source, $diagnostic[2]);
					if ($call === $site['occurrence']) { $matches[] = $call; }
				}
				if (count($matches) !== 1) { throw new \RuntimeException('Expected exactly one refusal per frozen site and side'); }
				$key = self::key($side, $identity, $matches[0]);
				if (isset($this->allowed[$key])) { throw new \RuntimeException('Duplicate/overlapping frozen site'); }
				$this->allowed[$key] = false; $this->identities[$physical] = $identity;
			}
		}
	}

	/** @param int $side Before=0, after=1. @param string $path Physical path. @param NormalizedSource $source Exact compared stream. @param array{int,string,int|null} $diagnostic Located refusal. @return bool Whether this exact eligible occurrence was consumed once. */
	public function accepts(int $side, string $path, NormalizedSource $source, array $diagnostic): bool {
		if (!isset($this->identities[$path], $diagnostic[2]) || !in_array($diagnostic[1], ['dirname path not proved', 'file_get_contents path not proved'], true)) { return false; }
		try { $call = FrozenPathOccurrence::at($source, $diagnostic[2]); } catch (\Throwable) { return false; }
		$key = self::key($side, $this->identities[$path], $call);
		if (!array_key_exists($key, $this->allowed) || $this->allowed[$key]) { return false; }
		$this->allowed[$key] = true; return true;
	}

	/** @param int $side Physical side, even when both normalized identities coincide. @param string $identity Historical map identity. @param array<string,mixed> $call Full canonical occurrence. @return string Exact consumption key. */
	private static function key(int $side, string $identity, array $call): string { return $side . ':' . FrozenPathOccurrence::key($identity, $call); }

	/** @return list<string> Invalid certificate and unused one-to-one authorizations. */
	public function problems(): array {
		$result = $this->errors;
		foreach ($this->allowed as $key => $used) { if (!$used) { $result[] = self::PATH . ': Unconsumed frozen occurrence: ' . $key; } }
		return $result;
	}
}
