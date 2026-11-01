<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Proves only an external node's exact runtime getPath operand, with an optional string cast. */
final class RuntimeNodePath {
	/** Prevents instantiation of the token proof. */
	private function __construct() {
	}

	/**
	 * Refuses this, nullsafe calls, composition, other methods and extra arguments.
	 * Builtin-call identity is proved by PathSafety; exact operands remain in the normal token comparison.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i builtin dirname callable token
	 * @return string|null refusal reason, or null for the supported runtime operand
	 */
	public static function unproved(array $tokens, int $i): ?string {
		$start = $i + 2;
		if (($tokens[$start][0] ?? null) === T_STRING_CAST) {
			$start++;
		}
		$operand = array_slice($tokens, $start, 6);
		$runtime = count($operand) === 6 && $operand[0][0] === T_VARIABLE && $operand[1][0] === T_OBJECT_OPERATOR
			&& $operand[0][1] !== '$this' && $operand[2][0] === T_STRING && strtolower($operand[2][1]) === 'getpath'
			&& array_column(array_slice($operand, 3), 1) === ['(', ')', ')'];
		return $runtime ? null : $tokens[$i][1] . ' path not proved';
	}
}
