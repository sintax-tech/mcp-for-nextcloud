<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Fingerprints the entire production universe; no call edge can be omitted by a receiver heuristic. */
final class FrozenProduction {
	/** @param array<string,string> $files Immutable app tree. @param SourceTreeIndex $index Tree declarations. @param array<string,string> $renames Canonical class transitions. @param array<string,string> $paths Canonical physical transitions. @param array<string,string> $identities Physical path to historical identity. @return array<string,string> Sorted complete production fingerprints. */
	public static function inventory(array $files, SourceTreeIndex $index, array $renames, array $paths, array $identities = []): array {
		$result = []; $normalizer = new TokenNormalizer($index);
		foreach ($files as $path => $code) {
			$php = str_ends_with($path, '.php');
			if (!str_starts_with($path, 'lib/') && !str_starts_with($path, 'appinfo/')
				&& !in_array($path, ['composer.json', 'composer.lock'], true)
				&& (!$php || str_starts_with($path, 'tests/') || str_starts_with($path, 'scripts/'))) { continue; }
			if ($php) { token_get_all($code, TOKEN_PARSE); }
			$identity = $identities[$path] ?? $path;
			if (isset($result[$identity])) { throw new \RuntimeException('Duplicate production identity: ' . $identity); }
			$result[$identity] = $php ? self::hash(array_column($normalizer->normalize($code, $renames, $path, $paths)->stream, 0)) : hash('sha256', $code);
		}
		ksort($result); return $result;
	}

	/** @param mixed $value Canonical ordered data without source lines. @return string SHA256 of deterministic JSON. */
	public static function hash(mixed $value): string {
		return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string,string> $pairs Exact physical file pairs. @return string Order-independent fingerprint of the complete pair set. */
	public static function pairsHash(array $pairs): string { ksort($pairs); return self::hash($pairs); }
}
