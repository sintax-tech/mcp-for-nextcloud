<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Scripts\Dev;

use RuntimeException;

/** Locates one reviewed parameter consumer without granting any general dynamic-path syntax. */
final class FrozenPathCall {
	/** @param string $code PHP source. @return list<array{0:int|null,1:string,2:int}> Significant tokens with source lines. */
	public static function tokens(string $code): array {
		$result = []; $line = 1;
		foreach (token_get_all($code, TOKEN_PARSE) as $token) {
			[$id, $text, $start] = is_array($token) ? $token : [null, $token, $line];
			$line = $start + substr_count($text, "\n");
			if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $result[] = [$id, $text, $start]; }
		}
		return $result;
	}

	/** @param list<array{0:int|null,1:string,2:int}> $tokens Tokens. @param int $open Opening parenthesis/brace. @return int Matching closing index. @throws RuntimeException Unclosed group. */
	private static function close(array $tokens, int $open): int {
		$left = $tokens[$open][1]; $right = $left === '(' ? ')' : '}'; $depth = 0;
		for ($i = $open; isset($tokens[$i]); $i++) {
			$depth += $tokens[$i][1] === $left ? 1 : ($tokens[$i][1] === $right ? -1 : 0);
			if ($depth === 0) { return $i; }
		}
		throw new RuntimeException('Unclosed frozen path group');
	}

	/** @param array<string,mixed> $site Reviewed site. @param string $code Complete frozen source. @param SourceTreeIndex $index Revision declarations. @return array{int,string} Exact source line and location refusal discharged. @throws RuntimeException Site identity, parameter, builtin, transform or operand differs. */
	public static function locate(array $site, string $code, SourceTreeIndex $index): array {
		$tokens = self::tokens($code); $scope = new ImportScope();
		foreach ($tokens as $i => [$id]) {
			if ($id === T_NAMESPACE) { $scope->readNamespace($tokens, $i); }
			if ($id === T_USE) { $scope->readImport($tokens, $i); }
			if ($id === T_CLASS) { break; }
		}
		$methods = [];
		foreach ($tokens as $i => [$id]) {
			if ($id === T_FUNCTION && ($tokens[$i + 1][1] ?? '') === $site['method'] && ($tokens[$i + 2][1] ?? '') === '(') { $methods[] = $i; }
		}
		if (count($methods) !== 1) { throw new RuntimeException('Frozen path method is missing or ambiguous'); }
		$open = $methods[0] + 2; $end = self::close($tokens, $open); $parameters = [];
		for ($i = $open + 1; $i < $end; $i++) {
			if ($tokens[$i][0] === T_VARIABLE && $tokens[$i][1] === $site['parameter']) {
				if (strtolower($tokens[$i - 1][1]) !== 'string' || !in_array($tokens[$i + 1][1], [',', ')'], true)) { throw new RuntimeException('Frozen path parameter must be a required string passed by value'); }
				$parameters[] = $i;
			}
		}
		if (count($parameters) !== 1) { throw new RuntimeException('Missing frozen path parameter'); }
		$body = $end + 1;
		while (isset($tokens[$body]) && $tokens[$body][1] !== '{') { $body++; }
		$last = self::close($tokens, $body); $calls = [];
		for ($i = $body + 1; $i < $last; $i++) {
			if (in_array($tokens[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && strtolower(ltrim($tokens[$i][1], '\\')) === $site['callable']
				&& ($tokens[$i + 1][1] ?? '') === '(' && !in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) { $calls[] = $i; }
		}
		$i = $calls[$site['ordinal'] - 1] ?? throw new RuntimeException('Missing frozen path call ordinal');
		if (!PathExpressions::builtin($tokens, $i, $scope, $index)) { throw new RuntimeException('Frozen path callable is not the proved builtin'); }
		$close = self::close($tokens, $i + 1);
		$operand = array_slice($tokens, $i + 2, $close - $i - 2);
		self::parameterOperand($site, $operand, $scope);
		return [$tokens[$i][2], $tokens[$i][1] . ' path not proved'];
	}

	/** @param list<array{0:int|null,1:string,2:int}> $operand Exact operand. @return string SHA256 of token kinds/texts without lines. */
	public static function operandHash(array $operand): string {
		$parts = array_map(static fn (array $t): array => [$t[0] === null ? 'CHAR' : token_name($t[0]), $t[1]], $operand);
		return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string,mixed> $site Reviewed transform. @param list<array{0:int|null,1:string,2:int}> $operand Tokens. @param ImportScope $scope Class imports. @return void @throws RuntimeException Not the frozen parameter/normalization shape. */
	private static function parameterOperand(array $site, array $operand, ImportScope $scope): void {
		$text = array_column($operand, 1); $transform = $site['transform'];
		if ($transform['kind'] === 'parameter' && count($operand) === 1 && $operand[0][0] === T_VARIABLE && $text === [$site['parameter']]) { return; }
		if ($transform['kind'] === 'path-guard' && in_array($operand[0][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
			&& $scope->resolveClass($text[0]) === $transform['class'] && array_slice($text, 1, 4) === ['::', 'normalize', '(', $site['parameter']]) {
			$tail = array_slice($operand, 5);
			if ($transform['label'] === null && array_column($tail, 1) === [')']) { return; }
			if (count($tail) === 3 && $tail[0][1] === ',' && $tail[1][0] === T_CONSTANT_ENCAPSED_STRING && PhpStringLiteral::value($tail[1][1]) === $transform['label'] && $tail[2][1] === ')') { return; }
		}
		throw new RuntimeException('Frozen path parameter transform differs');
	}
}
