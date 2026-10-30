<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Every class, interface, trait and enum declared by the PHP files of one tree, by file.
 *
 * Lookups are case-insensitive, as class names are in PHP.
 */
final class SourceTreeIndex {
	/** @var array<string,list<string>> path => fully qualified names declared there, in source order */
	private array $byFile = [];

	/** @var array<string,true> lower-case fully qualified name => true */
	private array $declared = [];

	/**
	 * @param array<string,string> $files path => content; only `.php` files are read
	 */
	public function __construct(array $files) {
		foreach ($files as $path => $content) {
			if (!str_ends_with($path, '.php')) {
				continue;
			}
			$this->byFile[$path] = self::declarations($content);
			foreach ($this->byFile[$path] as $class) {
				$this->declared[strtolower($class)] = true;
			}
		}
	}

	/**
	 * Tells whether the tree declares a class with this name.
	 *
	 * @param string $class fully qualified name, without leading backslash
	 * @return bool true when some file declares it
	 */
	public function declares(string $class): bool {
		return isset($this->declared[strtolower($class)]);
	}

	/**
	 * Names declared by one file.
	 *
	 * @param string $path path inside the tree
	 * @return list<string> fully qualified names in source order; empty for an unknown path
	 */
	public function declaredIn(string $path): array {
		return $this->byFile[$path] ?? [];
	}

	/**
	 * Scans PHP source for named class-like declarations (anonymous classes and `::class` are not declarations).
	 *
	 * @param string $code PHP source
	 * @return list<string> fully qualified names in source order
	 */
	public static function declarations(string $code): array {
		$tokens = array_values(array_filter(token_get_all($code), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$namespace = '';
		$found = [];
		foreach ($tokens as $i => $token) {
			if (!is_array($token)) {
				continue;
			}
			if ($token[0] === T_NAMESPACE) {
				$next = $tokens[$i + 1] ?? null;
				$namespace = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) ? $next[1] : '';
			}
			if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
				continue;
			}
			$previous = $tokens[$i - 1] ?? null;
			$next = $tokens[$i + 1] ?? null;
			if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
				continue;
			}
			if (is_array($next) && $next[0] === T_STRING) {
				$found[] = ($namespace === '' ? '' : $namespace . '\\') . $next[1];
			}
		}
		return $found;
	}
}
