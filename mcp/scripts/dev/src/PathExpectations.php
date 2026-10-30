<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Refuses inline path expectations whose order may depend on a sorted filesystem inventory. */
final class PathExpectations {
	/**
	 * Conservatively requires sorted expected values, rather than rewriting array order in a move proof.
	 * An assigned expected array explicitly sorted in the test is permitted; inline path expectations are not.
	 *
	 * @param string $path test file path
	 * @param string $code PHP source
	 * @param array<string,string> $pairs actual file pairs
	 * @return list<string> file/line diagnostics
	 */
	public static function problems(string $path, string $code, array $pairs): array {
		if ($pairs === [] || !str_starts_with($path, 'tests/') || !preg_match('/\b(?:k?sort|usort)\s*\(/', $code)) {
			return [];
		}
		$tokens = array_values(array_filter(token_get_all($code), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$problems = [];
		foreach ($tokens as $i => $token) {
			if (!is_array($token) || !in_array(strtolower($token[1]), ['assertsame', 'assertequals'], true) || ($tokens[$i + 1] ?? '') !== '(') {
				continue;
			}
			$array = $tokens[$i + 2] ?? null;
			$long = is_array($array) && $array[0] === T_ARRAY && ($tokens[$i + 3] ?? '') === '(';
			if ($array !== '[' && !$long) {
				continue;
			}
			$depth = 1;
			for ($j = $i + ($long ? 4 : 3); isset($tokens[$j]) && $depth > 0; $j++) {
				$part = $tokens[$j];
				$depth += in_array($part, ['[', '('], true) ? 1 : (in_array($part, [']', ')'], true) ? -1 : 0);
				if (!is_array($part) || $part[0] !== T_CONSTANT_ENCAPSED_STRING) {
					continue;
				}
				$value = PhpStringLiteral::value($part[1]);
				foreach ($pairs as $old => $new) {
					if (PathIdentity::match($value, $old) !== null || PathIdentity::match($value, $new) !== null) {
						$problems[] = "$path:{$part[2]}: path not proved: inline expected path order depends on a sorted inventory; prepare the test before moving";
					}
				}
			}
		}
		return array_values(array_unique($problems));
	}
}
