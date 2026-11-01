<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture\Dependencies;

use OCA\Mcp\Scripts\Dev\ImportScope;

require_once __DIR__ . '/../../../scripts/dev/bootstrap.php';

/** Reads executable class names and PHPDoc types, without treating documentation prose as imports. */
final class ClassReferences {
	/** @param string $source PHP source. @return list<array{target:string,line:int,kind:string}> Resolved references. */
	public static function read(string $source): array {
		$tokens = array_values(array_filter(token_get_all($source), static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT], true)));
		$tokens = array_map(static fn ($t) => is_array($t) ? $t : [null, $t, 0], $tokens);
		$scope = new ImportScope();
		$result = [];
		$depth = 0;
		for ($i = 0; $i < count($tokens); $i++) {
			$token = $tokens[$i];
			[$id, $text, $line] = $token;
			if ($id === T_NAMESPACE) {
				$end = $scope->readNamespace($tokens, $i);
				for ($n = $i; $n <= $end; $n++) { $tokens[$n][0] = null; }
				continue;
			}
			if ($text === '{') { $depth++; }
			if ($text === '}') { $depth--; }
			if ($id === T_USE && $depth === 0 && ($tokens[$i + 1][1] ?? '') !== '(') {
				$end = $scope->readImport($tokens, $i);
				$chunk = implode('', array_map(static fn ($t) => $t[0] === T_AS ? ' as ' : (in_array($t[0], [T_FUNCTION, T_CONST], true) ? $t[1] . ' ' : $t[1]), array_slice($tokens, $i + 1, $end - $i - 1)));
				foreach (self::imports($chunk) as $name) { $result[] = ['target' => $name, 'line' => $line, 'kind' => 'import']; }
				for ($n = $i; $n <= $end; $n++) { $tokens[$n][0] = null; }
				continue;
			}
			if ($id === T_DOC_COMMENT) {
				preg_match_all('/@(param|return|var|throws|property(?:-read|-write)?)\s+([^\s]+)/', $text, $types);
				foreach ($types[2] as $type) {
					preg_match_all('/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', $type, $names);
					foreach ($names[0] as $name) {
						if (str_contains($name, '\\') || $scope->isImported($name)) { $result[] = ['target' => $scope->resolveClass($name), 'line' => $line, 'kind' => 'doc-type']; }
					}
				}
				continue;
			}
			if ($id === T_CONSTANT_ENCAPSED_STRING) {
				$name = self::literal($text);
				if (preg_match('/^\\\\?(?:OCA|OCP|Psr|Sabre|Smalot|Symfony)\\\\[A-Za-z0-9_\\\\]+$/D', $name)) {
					$result[] = ['target' => ltrim($name, '\\'), 'line' => $line, 'kind' => str_ends_with($name, '\\') ? 'dynamic-prefix' : 'literal'];
				}
				continue;
			}
			if (in_array(strtolower($text), ['self', 'parent', 'static', 'int', 'string', 'float', 'bool', 'mixed', 'void', 'never', 'object', 'iterable', 'null', 'false', 'true'], true)) { continue; }
			if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) { continue; }
			$previous = $tokens[$i - 1][0] ?? null;
			if (in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_AS], true)) { continue; }
			if ($id !== T_STRING || $scope->isImported($text) || ($tokens[$i + 1][0] ?? null) === T_VARIABLE || self::isTypePosition($tokens, $i) || in_array($previous, [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_USE], true)) {
				$result[] = ['target' => $scope->resolveClass($text), 'line' => $line, 'kind' => 'code'];
			}
		}
		return array_values(array_unique($result, SORT_REGULAR));
	}

	/** @param array<mixed> $tokens Significant tokens. @param int $i Name position. @return bool Signature/property type, not a bitwise constant or ternary expression. */
	private static function isTypePosition(array $tokens, int $i): bool {
		for ($j = $i + 1; isset($tokens[$j]); $j++) {
			if ($tokens[$j][0] === T_VARIABLE) { return true; }
			if (!in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true) && !in_array($tokens[$j][1], ['|', '&', '?'], true)) { break; }
		}
		$j = $i - 1;
		while ($j >= 0 && (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || in_array($tokens[$j][1], ['|', '&', '?'], true))) { $j--; }
		if (($tokens[$j][1] ?? '') !== ':' || ($tokens[$j - 1][1] ?? '') !== ')') { return false; }
		$depth = 1; $j -= 2;
		while ($j >= 0 && $depth) { if ($tokens[$j][1] === ')') { $depth++; } if ($tokens[$j][1] === '(') { $depth--; } $j--; }
		if (($tokens[$j][0] ?? null) === T_STRING) { $j--; }
		if (($tokens[$j][1] ?? '') === '&') { $j--; }
		return in_array($tokens[$j][0] ?? null, [T_FUNCTION, T_FN], true);
	}

	/** @param string $chunk Import statement without use/semicolon. @return list<string> Imported classes only. */
	private static function imports(string $chunk): array {
		if (preg_match('/^(function|const)\b/', $chunk)) { return []; }
		$prefix = '';
		if (str_contains($chunk, '{')) { [$prefix, $chunk] = explode('{', $chunk, 2); $chunk = rtrim($chunk, '}'); }
		$names = [];
		foreach (explode(',', $chunk) as $item) {
			if (preg_match('/^(function|const)\b/', $item)) { continue; }
			$names[] = ltrim($prefix . preg_split('/\\s+as\\s+/i', trim($item), 2)[0], '\\');
		}
		return array_values(array_filter($names));
	}

	/** @param string $token Quoted PHP token. @return string Decoded without evaluating code. */
	private static function literal(string $token): string {
		$value = substr($token, 1, -1);
		if ($token[0] === "'") { return str_replace(["\\'", '\\\\'], ["'", '\\'], $value); }
		return preg_replace_callback('/\\\\([\\\\"$nrtvef])/', static fn ($m) => ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f"][$m[1]] ?? $m[1], $value);
	}
}
