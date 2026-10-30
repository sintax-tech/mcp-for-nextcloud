<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * One file after {@see TokenNormalizer}: the comparable stream and the class names it refers to.
 */
final class NormalizedSource {
	/**
	 * @param list<array{0:string,1:int}> $stream normalized tokens with the line each one came from
	 * @param list<array{0:string,1:int}> $references fully qualified class names referred to (resolved names,
	 *                                                unresolved same-namespace candidates and FQCN strings), with lines
	 * @param list<array{0:string,1:int}> $strings literal fragments in known filesystem path arguments, with lines
	 * @param list<array{0:int,1:string}> $located line and description of each construct whose value depends on the
	 *                                             file's folder or namespace and cannot be folded (relative include,
	 *                                             `__NAMESPACE__`)
	 */
	public function __construct(
		public readonly array $stream,
		public readonly array $references,
		public readonly array $strings = [],
		public readonly array $located = [],
	) {
	}

	/**
	 * Finds the first position where two streams differ.
	 *
	 * @param NormalizedSource $other the stream to compare with
	 * @return array{0:int,1:string,2:int,3:string}|null old line, old token, new line, new token; null when equal
	 */
	public function firstDifference(NormalizedSource $other): ?array {
		$length = max(count($this->stream), count($other->stream));
		for ($i = 0; $i < $length; $i++) {
			$mine = $this->stream[$i] ?? ['(end of file)', 0];
			$theirs = $other->stream[$i] ?? ['(end of file)', 0];
			if ($mine[0] !== $theirs[0]) {
				return [$mine[1], $mine[0], $theirs[1], $theirs[0]];
			}
		}
		return null;
	}
}
