<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Proves only magic-constant values and builtin dirname applied directly to one magic constant.
 * Concatenations remain separate, byte-preserved tokens: lexical cleanup of a suffix is never a filesystem proof.
 * The shape is compared too; only the integer depth of the same dirname expression may change during a move.
 */
final class PathExpressions {
	/** Filesystem functions whose first argument is a path. Unknown forms are refused in files changing folder. */
	public const CALLS = ['dirname', 'realpath', 'glob', 'is_file', 'file_exists', 'file_get_contents'];

	/**
	 * Folds a supported root; never consumes a concatenated suffix.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i position to try
	 * @param string $path file path relative to the app
	 * @param ImportScope $scope namespace/imports at the call
	 * @param SourceTreeIndex $index declarations in the tree
	 * @return array{0:string,1:int,2:string}|null value, last token, expression shape
	 */
	public static function fold(array $tokens, int $i, string $path, ImportScope $scope, SourceTreeIndex $index): ?array {
		$id = $tokens[$i][0];
		if ($id === T_DIR || $id === T_FILE) {
			return [self::constant($id, $path), $i, $tokens[$i][1]];
		}
		$name = $tokens[$i][1];
		if (strtolower(ltrim($name, '\\')) !== 'dirname' || !self::builtin($tokens, $i, $scope, $index)
			|| ($tokens[$i + 1][1] ?? '') !== '(' || !in_array($tokens[$i + 2][0] ?? null, [T_DIR, T_FILE], true)) {
			return null;
		}
		$levels = 1;
		$end = $i + 3;
		if (($tokens[$end][1] ?? '') === ',' && ($tokens[$end + 1][0] ?? null) === T_LNUMBER) {
			if (preg_match('/^[1-9][0-9]*$/D', $tokens[$end + 1][1]) !== 1) {
				return null;
			}
			$levels = (int)$tokens[$end + 1][1];
			$end += 2;
		}
		if (($tokens[$end][1] ?? '') !== ')' || $levels > substr_count($path, '/') + 1) {
			return null;
		}
		$value = self::constant($tokens[$i + 2][0], $path);
		for ($n = 0; $n < $levels; $n++) {
			$value = $value === '.' || str_starts_with($value, '..') ? ($value === '.' ? '..' : $value . '/..') : dirname($value);
		}
		return [$value, $end, $name . '(' . $tokens[$i + 2][1] . ')'];
	}

	/**
	 * Proves that a call names a global builtin, never a member, alias or namespace override.
	 * Unqualified folding is disabled in files with function imports, including imports later in the namespace.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i callable token
	 * @param ImportScope $scope current scope
	 * @param SourceTreeIndex $index tree declarations
	 * @return bool whether the call is proved global
	 */
	public static function builtin(array $tokens, int $i, ImportScope $scope, SourceTreeIndex $index): bool {
		$name = $tokens[$i][1];
		if (!in_array($tokens[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
			|| in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
			return false;
		}
		if (str_starts_with($name, '\\')) {
			return !str_contains(substr($name, 1), '\\');
		}
		$import = false;
		foreach ($tokens as [$id, $text]) {
			if ($id === T_USE) {
				$import = true;
			} elseif ($text === ';') {
				$import = false;
			} elseif ($import && $id === T_FUNCTION) {
				return false;
			}
		}
		return strtolower($scope->resolveFunction($name, $index)) === strtolower($name)
			&& !$index->declaresFunction($scope->qualify($name));
	}

	/**
	 * Recognizes an absolute literal or a proved magic root followed solely by literal concatenations.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i first argument token
	 * @param string $path source file path
	 * @param ImportScope $scope current scope
	 * @param SourceTreeIndex $index tree declarations
	 * @return int|null last token of a proved expression
	 */
	public static function target(array $tokens, int $i, string $path, ImportScope $scope, SourceTreeIndex $index): ?int {
		$folded = self::fold($tokens, $i, $path, $scope, $index);
		if ($folded !== null) {
			$end = $folded[1];
		} elseif (($tokens[$i][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
			&& str_starts_with(PhpStringLiteral::value($tokens[$i][1]), '/')) {
			$end = $i;
		} else {
			return null;
		}
		while (($tokens[$end + 1][1] ?? '') === '.' && ($tokens[$end + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
			$end += 2;
		}
		return $end;
	}

	/**
	 * Value of a magic constant; tree paths themselves are already relative and normalized by SourceTrees.
	 *
	 * @param int $id T_DIR or T_FILE
	 * @param string $path source path
	 * @return string path relative to the app
	 */
	private static function constant(int $id, string $path): string {
		return $id === T_FILE ? $path : dirname($path);
	}
}
