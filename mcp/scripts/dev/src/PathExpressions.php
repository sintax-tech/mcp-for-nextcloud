<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Folds the location-dependent expressions of a file into the path they evaluate to, relative to the app folder.
 *
 * `__DIR__`, `__FILE__`, `dirname(__DIR__|__FILE__[, levels])`, each optionally followed by `. 'literal'`, become
 * one `P:<path>` token computed from the file's own path in the tree. A moved file then compares equal only when
 * the expression still yields the same path (`dirname(__DIR__, 3)` in `tests/Unit/Service/` equals
 * `dirname(__DIR__, 4)` in `tests/Unit/Mcp/Prompts/`); any other use of `__DIR__`/`__FILE__` folds to the bare
 * constant's value, which changes with the folder and so fails the comparison.
 */
final class PathExpressions {
	/** Names that call PHP's dirname(). */
	private const DIRNAME = ['dirname', '\\dirname'];

	/**
	 * Folds the expression starting at a token, if it is one.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i position to try
	 * @param string $path path of the file in the tree, relative to the app folder
	 * @return array{0:string,1:int}|null the folded path and the position of its last token; null when no expression starts here
	 */
	public static function fold(array $tokens, int $i, string $path): ?array {
		$id = $tokens[$i][0];
		if ($id === T_DIR || $id === T_FILE) {
			[$value, $end] = [self::constant($id, $path), $i];
		} elseif (in_array(strtolower($tokens[$i][1]), self::DIRNAME, true) && ($tokens[$i + 1][1] ?? '') === '('
			&& in_array($tokens[$i + 2][0] ?? null, [T_DIR, T_FILE], true)) {
			$levels = 1;
			$end = $i + 3;
			if (($tokens[$end][1] ?? '') === ',' && ($tokens[$end + 1][0] ?? null) === T_LNUMBER) {
				$levels = (int)$tokens[$end + 1][1];
				$end += 2;
			}
			if (($tokens[$end][1] ?? '') !== ')' || $levels < 1) {
				return null;
			}
			$value = self::up(self::constant($tokens[$i + 2][0], $path), $levels);
		} else {
			return null;
		}
		if (($tokens[$end + 1][1] ?? '') === '.' && ($tokens[$end + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
			$value = self::canonical($value . PhpStringLiteral::value($tokens[$end + 2][1]));
			$end += 2;
		}
		return [$value, $end];
	}

	/**
	 * Value of `__DIR__` or `__FILE__` for a file.
	 *
	 * @param int $id T_DIR or T_FILE
	 * @param string $path path of the file, relative to the app folder
	 * @return string the value, relative to the app folder (`.` for the app folder itself)
	 */
	private static function constant(int $id, string $path): string {
		return $id === T_FILE ? self::canonical($path) : self::up(self::canonical($path), 1);
	}

	/**
	 * dirname() applied a number of times; above the app folder it keeps `..` segments.
	 *
	 * @param string $path path relative to the app folder
	 * @param int $levels levels to go up
	 * @return string the parent path
	 */
	private static function up(string $path, int $levels): string {
		return self::canonical($path . str_repeat('/..', $levels));
	}

	/**
	 * Resolves `.`, `..` and repeated slashes.
	 *
	 * @param string $path path relative to the app folder
	 * @return string canonical path, `.` for the app folder
	 */
	private static function canonical(string $path): string {
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		return $segments === [] ? '.' : implode('/', $segments);
	}
}
