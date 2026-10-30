<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Namespace and imports in force while a file is read, and PHP's name resolution rules over them.
 *
 * Class aliases are case-insensitive, as in PHP; function and constant imports are kept apart because they
 * resolve on their own tables. A new `namespace` statement clears the imports.
 */
final class ImportScope {
	/** Current namespace, without leading backslash; empty for the global namespace. */
	private string $namespace = '';

	/** @var array<string,string> lower-case alias => fully qualified class or namespace */
	private array $classes = [];

	/** @var array<string,string> lower-case alias => fully qualified function */
	private array $functions = [];

	/**
	 * Reads a `namespace` statement and switches to it.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i position of the `namespace` keyword
	 * @return int position of the last token consumed (the name, or the keyword of a global block)
	 */
	public function readNamespace(array $tokens, int $i): int {
		$this->classes = [];
		$this->functions = [];
		$next = $tokens[$i + 1] ?? [null, '', 0];
		if (in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
			$this->namespace = $next[1];
			return $tokens[$i + 2][1] === ';' ? $i + 2 : $i + 1;
		}
		$this->namespace = '';
		return $i;
	}

	/**
	 * Reads a file-level `use` statement (plain, function, const, grouped or several in one) and records it.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i position of the `use` keyword
	 * @return int position of the closing `;`
	 */
	public function readImport(array $tokens, int $i): int {
		$kind = 'class';
		$i++;
		if (in_array(strtolower($tokens[$i][1]), ['function', 'const'], true)) {
			$kind = strtolower($tokens[$i][1]);
			$i++;
		}
		$prefix = '';
		$name = '';
		$alias = null;
		for (; $tokens[$i][1] !== ';'; $i++) {
			$text = $tokens[$i][1];
			if ($text === '{') {
				$prefix = rtrim($name, '\\') . '\\';
				$name = '';
			} elseif ($text === ',' || $text === '}') {
				$this->record($kind, $prefix . $name, $alias);
				[$name, $alias] = ['', null];
			} elseif ($tokens[$i][0] === T_AS) {
				$alias = $tokens[++$i][1];
			} elseif ($tokens[$i][0] === T_NS_SEPARATOR) {
				$name .= '\\';
			} else {
				$name .= $text;
			}
		}
		$this->record($kind, $prefix . $name, $alias);
		return $i;
	}

	/**
	 * Resolves a name used as a class.
	 *
	 * @param string $name the name as written
	 * @return string fully qualified name, without leading backslash
	 */
	public function resolveClass(string $name): string {
		if (str_starts_with($name, '\\')) {
			return substr($name, 1);
		}
		if (str_starts_with(strtolower($name), 'namespace\\')) {
			return $this->qualify(substr($name, 10));
		}
		$parts = explode('\\', $name, 2);
		$imported = $this->classes[strtolower($parts[0])] ?? null;
		if ($imported !== null) {
			return isset($parts[1]) ? $imported . '\\' . $parts[1] : $imported;
		}
		return $this->qualify($name);
	}

	/**
	 * Resolves a name used as a function: imported functions resolve to their import, others stay as written
	 * (PHP falls back to the global function at run time).
	 *
	 * @param string $name the name as written
	 * @return string the import target, or the name unchanged
	 */
	public function resolveFunction(string $name): string {
		return $this->functions[strtolower($name)] ?? $name;
	}

	/**
	 * Tells whether an unqualified name is a class import of this file.
	 *
	 * @param string $name the name as written
	 * @return bool true when a `use` statement gave it
	 */
	public function isImported(string $name): bool {
		return isset($this->classes[strtolower(explode('\\', $name, 2)[0])]);
	}

	/**
	 * Prefixes the current namespace.
	 *
	 * @param string $name relative name
	 * @return string fully qualified name
	 */
	private function qualify(string $name): string {
		return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
	}

	/**
	 * Records one import.
	 *
	 * @param string $kind `class`, `function` or `const`
	 * @param string $name imported name, possibly with a leading backslash
	 * @param string|null $alias explicit alias, or null for the last segment
	 */
	private function record(string $kind, string $name, ?string $alias): void {
		$name = ltrim($name, '\\');
		if ($name === '') {
			return;
		}
		$segments = explode('\\', $name);
		$key = strtolower($alias ?? end($segments));
		if ($kind === 'class') {
			$this->classes[$key] = $name;
		} elseif ($kind === 'function') {
			$this->functions[$key] = $name;
		}
	}
}
