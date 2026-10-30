<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Inventory of the test methods of a tree, as `Class::method` identities.
 *
 * A test method is a public method named `test*`, or one marked with the `#[Test]` attribute or a `@test`
 * annotation, of a class declared under `tests/`. The class is its fully qualified name, so a moved test is
 * recognized through {@see TestIdentityMapper}.
 */
final class TestInventory {
	/**
	 * Lists the test methods of every PHP file under `tests/`.
	 *
	 * @param array<string,string> $files tree, path => content
	 * @return list<string> `Fully\Qualified\Class::method`, sorted
	 */
	public static function scan(array $files): array {
		$found = [];
		foreach ($files as $path => $content) {
			if (str_starts_with($path, 'tests/') && str_ends_with($path, '.php')) {
				array_push($found, ...self::methods($content));
			}
		}
		$found = array_values(array_unique($found));
		sort($found, SORT_STRING);
		return $found;
	}

	/**
	 * Test methods declared in one file.
	 *
	 * @param string $code PHP source
	 * @return list<string> `Class::method` identities
	 */
	private static function methods(string $code): array {
		$tokens = array_values(array_filter(token_get_all($code), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT], true)));
		$namespace = '';
		$class = null;
		$classDepth = -1;
		$depth = 0;
		$pendingClass = null;
		$marked = false;
		$found = [];
		foreach ($tokens as $i => $token) {
			$text = is_array($token) ? $token[1] : $token;
			$id = is_array($token) ? $token[0] : null;
			if ($id === T_NAMESPACE && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] !== T_NS_SEPARATOR) {
				$namespace = $tokens[$i + 1][1] === '{' ? '' : $tokens[$i + 1][1];
			} elseif (in_array($id, [T_CLASS, T_TRAIT], true) && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING
				&& !(is_array($tokens[$i - 1] ?? null) && in_array($tokens[$i - 1][0], [T_DOUBLE_COLON, T_NEW], true))) {
				$pendingClass = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$i + 1][1];
			} elseif ($id === T_DOC_COMMENT) {
				$marked = $marked || preg_match('/@test\b/', $text) === 1;
			} elseif ($id === T_ATTRIBUTE && preg_match('/^(\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test$/', $tokens[$i + 1][1] ?? '') === 1) {
				$marked = true;
			} elseif ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				if ($pendingClass !== null) {
					[$class, $classDepth, $pendingClass] = [$pendingClass, $depth, null];
				}
				$depth++;
			} elseif ($text === '}') {
				$depth--;
				if ($depth === $classDepth) {
					[$class, $classDepth] = [null, -1];
				}
			} elseif ($id === T_FUNCTION && $class !== null && $depth === $classDepth + 1) {
				$name = is_array($tokens[$i + 1] ?? null) ? $tokens[$i + 1][1] : '';
				if (($marked || str_starts_with($name, 'test')) && self::isPublic($tokens, $i)) {
					$found[] = "$class::$name";
				}
				$marked = false;
			} elseif ($text === ';' && $depth === $classDepth + 1) {
				$marked = false;
			}
		}
		return $found;
	}

	/**
	 * Tells whether the method starting at `function` is public (explicitly or by default).
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens tokens without whitespace and comments
	 * @param int $i position of `function`
	 * @return bool false for a private or protected method
	 */
	private static function isPublic(array $tokens, int $i): bool {
		for ($k = $i - 1; $k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_DOC_COMMENT], true); $k--) {
			if (in_array($tokens[$k][0], [T_PRIVATE, T_PROTECTED], true)) {
				return false;
			}
		}
		return true;
	}
}
