<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Refuses implicit include-path and reflection/stack location dependencies when a file moves. */
final class LocationSensitiveCalls {
	/** Functions whose results can depend on the executing file's location. */
	private const ALWAYS = ['stream_resolve_include_path', 'set_include_path', 'get_included_files', 'debug_backtrace', 'debug_print_backtrace'];

	/** First optional argument position that can enable include_path; presence suffices for conservative refusal. */
	private const OPTIONAL = ['fopen' => 3, 'gzopen' => 3, 'file' => 2, 'readfile' => 2, 'gzfile' => 2, 'readgzfile' => 2];

	/**
	 * Classifies the call at a token without evaluating its arguments.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i callable token
	 * @param ImportScope $scope current imports
	 * @param SourceTreeIndex $index declarations
	 * @return string|null reason the move cannot be proved equivalent
	 */
	public static function unproved(array $tokens, int $i, ImportScope $scope, SourceTreeIndex $index): ?string {
		if (!in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
			|| ($tokens[$i + 1][1] ?? '') !== '(') {
			return null;
		}
		$text = $tokens[$i][1];
		$previous = $tokens[$i - 1][0] ?? null;
		if (in_array(strtolower($text), ['getfilename', 'getfile'], true) && in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
			return "$text location not proved";
		}
		if (in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
			return null;
		}
		if ($previous === T_NEW) {
			$name = strtolower($scope->resolveClass($text));
			$threshold = $name === 'splfileobject' ? 3 : null;
		} else {
			$name = strtolower(ltrim($scope->resolveFunction($text, $index), '\\'));
			if (in_array($name, self::ALWAYS, true)) {
				return "$text location not proved";
			}
			$threshold = self::OPTIONAL[$name] ?? null;
		}
		if ($threshold === null) {
			return null;
		}
		$depth = 0;
		$argument = 1;
		for ($j = $i + 2; isset($tokens[$j]); $j++) {
			$part = $tokens[$j][1];
			if ($depth === 0 && $part === ')') {
				break;
			}
			if ($depth === 0 && (($part === ',' && ++$argument >= $threshold)
				|| (in_array(strtolower($part), ['use_include_path', 'useincludepath'], true) && ($tokens[$j + 1][1] ?? '') === ':') || $tokens[$j][0] === T_ELLIPSIS)) {
				return "$text include path not proved";
			}
			$depth += in_array($part, ['(', '[', '{'], true) ? 1 : (in_array($part, [')', ']', '}'], true) ? -1 : 0);
		}
		return null;
	}
}
