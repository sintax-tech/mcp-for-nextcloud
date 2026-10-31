<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Applies only the actual file pairs, preserving inventory suffixes and refusing unproved prefixes or backup/child names. */
final class PathRewrites {
	/**
	 * Renames a whole literal value if it names a paired file.
	 *
	 * @param string $value decoded literal
	 * @param array<string,string> $pairs old path => new path (identity pairs normalize the after side)
	 * @return string|null renamed value, null when it is not a paired path
	 */
	public static function value(string $value, array $pairs): ?string {
		foreach ($pairs as $old => $new) {
			$match = PathIdentity::match($value, $old);
			if ($match !== null && PathIdentity::proved($match)) {
				return $match[0] . ($match[2] && str_starts_with($new, 'lib/') ? substr($new, 4) : $new) . $match[1];
			}
		}
		return null;
	}

	/**
	 * Folds consecutive literal concatenations only when the entire value names a paired path.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant PHP tokens
	 * @param int $i first literal
	 * @param array<string,string> $pairs path renames
	 * @return array{string,int}|null renamed value and last token
	 */
	public static function literal(array $tokens, int $i, array $pairs): ?array {
		if (($tokens[$i - 1][1] ?? '') === '.' && ($tokens[$i - 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
			return null;
		}
		$value = PhpStringLiteral::value($tokens[$i][1]);
		$end = $i;
		while (($tokens[$end + 1][1] ?? '') === '.' && ($tokens[$end + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
			$value .= PhpStringLiteral::value($tokens[$end + 2][1]);
			$end += 2;
		}
		$renamed = self::value($value, $pairs);
		return $renamed === null ? null : [$renamed, $end];
	}

	/**
	 * Collects whole literal values, including lib-relative expectations and inventory keys outside file calls.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant PHP tokens
	 * @return list<array{string,int}> concatenated values and their lines
	 */
	public static function references(array $tokens): array {
		$found = [];
		for ($i = 0; isset($tokens[$i]); $i++) {
			if ($tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
				continue;
			}
			$value = PhpStringLiteral::value($tokens[$i][1]);
			$line = $tokens[$i][2];
			while (($tokens[$i + 1][1] ?? '') === '.' && ($tokens[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
				$value .= PhpStringLiteral::value($tokens[$i + 2][1]);
				$i += 2;
			}
			$found[] = [$value, $line];
		}
		return $found;
	}

	/**
	 * Rewrites whole path tokens in non-PHP tests; every byte outside those tokens stays unchanged.
	 *
	 * @param string $text original test text
	 * @param array<string,string> $pairs actual file pairs
	 * @return string expected text after path-only edits
	 */
	public static function text(string $text, array $pairs): string {
		return preg_replace_callback('~[A-Za-z0-9_./-]+~', static function (array $m) use ($pairs, $text): string {
			[$value, $offset] = $m[0];
			return self::repoBoundary($value, $text, $offset) ? (self::value($value, $pairs) ?? $value) : $value;
		}, $text, flags: PREG_OFFSET_CAPTURE);
	}

	/**
	 * Checks all non-PHP changes and retired path tokens in non-PHP tests, including unchanged callers.
	 *
	 * @param array<string,string> $before old tree
	 * @param array<string,string> $after new tree
	 * @param array<string,string> $pairs actual PHP file pairs
	 * @return list<string> diagnostics
	 */
	public static function nonPhp(array $before, array $after, array $pairs): array {
		$problems = [];
		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
			if (str_ends_with($path, '.php') || in_array($path, MoveEquivalence::ALLOWED_DATA, true)) {
				continue;
			}
			$changed = ($before[$path] ?? null) !== ($after[$path] ?? null);
			$test = str_starts_with($path, 'tests/');
			if ($changed && (!$test || !isset($before[$path], $after[$path]) || self::text($before[$path], $pairs) !== $after[$path])) {
				$problems[] = $test
					? "$path: non-PHP file changed beyond paired test paths; only actual file-pair substitutions are allowed"
					: "$path: non-PHP file changed; a move commit only moves PHP and updates the map";
			}
			if (!$test || !isset($after[$path]) || $pairs === []) {
				continue;
			}
			preg_match_all('~[A-Za-z0-9_./-]+~', $after[$path], $matches, PREG_OFFSET_CAPTURE);
			foreach ($matches[0] as [$value, $offset]) {
				foreach ($pairs + array_combine(array_values($pairs), array_values($pairs)) as $old => $new) {
					if (PathIdentity::namesDestination($value, $old, $new)) {
						continue;
					}
					$match = PathIdentity::match($value, $old);
					if ($match !== null) {
						$line = substr_count(substr($after[$path], 0, $offset), "\n") + 1;
						if (!PathIdentity::proved($match) || !self::repoBoundary($value, $after[$path], $offset)) {
							$problems[] = "$path:$line: path not proved: unrecognized prefix in $value";
						} elseif (isset($pairs[$old])) {
							$problems[] = "$path:$line: still refers to the file $old, moved to $new in this range";
						}
					}
				}
			}
		}
		return $problems;
	}
	/**
	 * Repository-relative tokens must begin a value or a whitespace-separated field, not an embedded prefix.
	 *
	 * @param string $value matched path token
	 * @param string $text complete non-PHP text
	 * @param int $offset token byte offset
	 * @return bool whether a repository-root prefix is proved at this boundary
	 */
	private static function repoBoundary(string $value, string $text, int $offset): bool {
		return !str_starts_with($value, 'mcp/') || $offset === 0
			|| ctype_space($text[$offset - 1]) || in_array($text[$offset - 1], ["'", '"', '`'], true);
	}

}
