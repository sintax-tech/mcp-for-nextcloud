<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Identifies path calls in the same canonical stream used for source equivalence, never by source line. */
final class FrozenPathOccurrence {
	/** @param NormalizedSource $source Canonical source. @param int $offset Exact canonical call token. @return array<string,mixed> Method, builtin, ordinal, source/operand hashes and offset. */
	public static function at(NormalizedSource $source, int $offset): array {
		$tokens = array_column($source->stream, 0); $method = null; $calls = []; $matches = [];
		foreach ($tokens as $i => $token) {
			if ($token !== 'T:function' || !isset($tokens[$i + 1]) || $tokens[$i + 1] === 'T:(') { continue; }
			$name = $tokens[$i + 1];
			if (!str_starts_with($name, 'T:') || ($tokens[$i + 2] ?? '') !== 'T:(') { continue; }
			$start = self::close($tokens, $i + 2) + 1;
			while (isset($tokens[$start]) && !in_array($tokens[$start], ['T:{', 'T:;'], true)) { $start++; }
			if (($tokens[$start] ?? '') !== 'T:{') { continue; }
			$end = self::close($tokens, $start);
			if ($offset > $start && $offset < $end) { $matches[] = [substr($name, 2), $start, $end]; }
		}
		if (count($matches) !== 1) { throw new \RuntimeException('Missing or ambiguous frozen call method'); }
		[$method, $start, $end] = $matches[0];
		$callable = strtolower(ltrim(substr($tokens[$offset] ?? '', 2), '\\'));
		if (!in_array($callable, ['dirname', 'file_get_contents'], true) || !str_starts_with($tokens[$offset], 'F:') || ($tokens[$offset + 1] ?? '') !== 'T:(') { throw new \RuntimeException('Not a canonical path builtin'); }
		$ordinal = 0;
		for ($i = $start + 1; $i <= $offset; $i++) {
			if (str_starts_with($tokens[$i], 'F:') && strtolower(ltrim(substr($tokens[$i], 2), '\\')) === $callable && ($tokens[$i + 1] ?? '') === 'T:(') { $ordinal++; }
		}
		$close = self::close($tokens, $offset + 1);
		return ['method' => $method, 'callable' => $callable, 'ordinal' => $ordinal, 'source_sha256' => FrozenProduction::hash($tokens),
			'operand_sha256' => FrozenProduction::hash(array_slice($tokens, $offset + 2, $close - $offset - 2)), 'offset' => $offset];
	}

	/** @param list<string> $tokens Canonical tokens. @param int $open Opening group. @return int Matching closing position. */
	private static function close(array $tokens, int $open): int {
		$left = $tokens[$open] ?? ''; $right = match ($left) { 'T:(' => 'T:)', 'T:{' => 'T:}', default => throw new \RuntimeException('Invalid frozen group') }; $depth = 0;
		for ($i = $open; isset($tokens[$i]); $i++) {
			$depth += $tokens[$i] === $left ? 1 : ($tokens[$i] === $right ? -1 : 0);
			if ($depth === 0) { return $i; }
		}
		throw new \RuntimeException('Unclosed frozen group');
	}

	/** @param string $identity Historical map identity. @param array<string,mixed> $call Canonical occurrence data. @return string Complete collision-free authorization identity. */
	public static function key(string $identity, array $call): string { return json_encode([$identity, $call], JSON_THROW_ON_ERROR); }
}
