<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Conservative checks for path consumers; this is deliberately not a general PHP data-flow evaluator. */
final class PathSafety {
	/** Include and require tokens. */
	private const INCLUDES = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];

	/**
	 * Reports path consumers whose first argument cannot be proved location independent or a supported root.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i current token
	 * @param string $path source file path
	 * @param ImportScope $scope current scope
	 * @param SourceTreeIndex $index declarations
	 * @return string|null reason to refuse when the folder changes
	 */
	public static function unproved(array $tokens, int $i, string $path, ImportScope $scope, SourceTreeIndex $index): ?string {
		[, $text] = $tokens[$i];
		$include = self::includes($tokens, $i);
		$call = in_array(strtolower(ltrim($text, '\\')), PathExpressions::CALLS, true) && ($tokens[$i + 1][1] ?? '') === '(';
		if (!$include && !$call) {
			return null;
		}
		$paren = ($tokens[$i + 1][1] ?? '') === '(';
		$start = $i + ($paren ? 2 : 1);
		$end = PathExpressions::target($tokens, $start, $path, $scope, $index);
		$terminators = [')', ','];
		if ($call && !PathExpressions::builtin($tokens, $i, $scope, $index)) {
			return "$text path callable not proved builtin";
		}
		// dirname itself is proved only by the narrow root rule, never by evaluating an arbitrary argument.
		if ($call && strtolower(ltrim($text, '\\')) === 'dirname') {
			return PathExpressions::fold($tokens, $i, $path, $scope, $index) === null ? "$text path not proved" : null;
		}
		if ($include && $end !== null) {
			$last = $end + ($paren ? 1 : 0);
			return (!$paren || ($tokens[$last][1] ?? '') === ')') && ($tokens[$last + 1][1] ?? '') === ';'
				? null : "$text path not proved";
		}
		if ($end !== null && in_array($tokens[$end + 1][1] ?? '', $terminators, true)) {
			return null;
		}
		if ($include && ($tokens[$start][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
			$value = PhpStringLiteral::value($tokens[$start][1]);
			return "relative {$text} '$value'";
		}
		return "$text path not proved";
	}

	/**
	 * Collects string literals only inside the path argument of known filesystem calls or includes.
	 * No inference from arbitrary prose, constants, assignments or a filename-like substring is made.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @return list<array{0:string,1:int}> literal path fragments and source lines
	 */
	public static function references(array $tokens): array {
		$found = [];
		foreach ($tokens as $i => [, $text]) {
			$include = self::includes($tokens, $i);
			$call = in_array(strtolower(ltrim($text, '\\')), PathExpressions::CALLS, true) && ($tokens[$i + 1][1] ?? '') === '(';
			if (!$include && !$call) {
				continue;
			}
			$start = $i + (($tokens[$i + 1][1] ?? '') === '(' ? 2 : 1);
			$depth = 0;
			for ($j = $start; isset($tokens[$j]); $j++) {
				[$part, $value, $line] = $tokens[$j];
				if ($depth === 0 && in_array($value, [')', ',', ';'], true)) {
					break;
				}
				$depth += $value === '(' ? 1 : ($value === ')' ? -1 : 0);
				if ($part === T_CONSTANT_ENCAPSED_STRING) {
					$literal = PhpStringLiteral::value($value);
					while (($tokens[$j + 1][1] ?? '') === '.' && ($tokens[$j + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
						$literal .= PhpStringLiteral::value($tokens[$j + 2][1]);
						$j += 2;
					}
					$found[$j] = [$literal, $line];
				}
			}
		}
		return array_values($found);
	}

	/**
	 * Whether the token is an include or require statement. Without TOKEN_PARSE, a method named after the keyword
	 * (`function require(`, `PlanState::require(`, `->include(`) is lexed as the keyword too; it reads no file.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i current token
	 * @return bool true for an include or require statement
	 */
	private static function includes(array $tokens, int $i): bool {
		return in_array($tokens[$i][0], self::INCLUDES, true)
			&& !in_array($tokens[$i - 1][0] ?? null, [T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
	}

	/**
	 * Matches whole app/lib-relative paths and optional inventory suffixes; prefix proof is checked separately.
	 *
	 * @param string $value path literal
	 * @param string $old retired relative path
	 * @return bool whether the literal names the old file
	 */
	public static function names(string $value, string $old): bool {
		return PathIdentity::match($value, $old) !== null;
	}
}
