<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Turns PHP source into a token stream in which every class reference is written as its fully qualified name.
 *
 * Imports (`use` at file level), `namespace` statements, whitespace and comments are left out of the stream: they
 * only decide how names resolve, and the resolved names are what is compared. A class reference is any name the
 * code uses as a class: an imported or qualified name, `Foo::class`, a type, `new`, `extends`, `catch`, an
 * attribute, a declaration. An unqualified name that is not imported counts as a class only when the current
 * namespace declares a class with that name in the same tree ({@see SourceTreeIndex}); otherwise it is a function,
 * a constant or a builtin type and stays literal. A string literal whose value is a fully qualified class name is
 * compared by that value ({@see PhpStringLiteral}), so `'OCA\\Mcp\\X'` and `'OCA\Mcp\X'` are the same string; any
 * other string, interpolated text and heredoc content is compared byte for byte. Inside an attribute (`#[...]`)
 * the attribute names are classes even when arguments follow. Constants imported with `use const` resolve to
 * their import.
 *
 * Deliberately not compared: whitespace between tokens, comments and PHPDoc (a type named only in `@param` or
 * `@var` does not run; a stale one there is left to human review) and the order of imports.
 *
 * Renames are applied while normalizing, so the stream of the old file with the map's renames applied must equal
 * the stream of the new file with none.
 */
final class TokenNormalizer {
	/** Names that are never class references, whatever the namespace declares. */
	private const RESERVED = ['self', 'parent', 'static', 'true', 'false', 'null'];

	/** Tokens left out of the stream. */
	private const SKIPPED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

	/** Tokens after which a name is a member, function or constant name, never a class. */
	private const MEMBER_PREFIXES = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_GOTO];

	/** Tokens that hold a (possibly qualified) name. */
	private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

	/** Shape of a fully qualified class name inside a string literal. */
	private const FQCN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

	/**
	 * @param SourceTreeIndex $index declared classes of the tree the source belongs to
	 */
	public function __construct(private readonly SourceTreeIndex $index) {
	}

	/**
	 * Normalizes one file.
	 *
	 * @param string $code PHP source
	 * @param array<string,string> $renames old FQCN => new FQCN, applied to every resolved class name and FQCN string
	 * @return NormalizedSource the stream and every class name the source refers to
	 */
	public function normalize(string $code, array $renames = []): NormalizedSource {
		$tokens = $this->significant($code);
		$scope = new ImportScope();
		$stream = [];
		$references = [];
		$contexts = [];
		$pendingClass = false;
		$attribute = null;
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			[$id, $text, $line] = $tokens[$i];
			if ($id === T_NAMESPACE) {
				$i = $scope->readNamespace($tokens, $i);
				continue;
			}
			if ($id === T_USE && end($contexts) !== 'class' && ($tokens[$i + 1][1] ?? '') !== '(') {
				$i = $scope->readImport($tokens, $i);
				continue;
			}
			if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && !in_array($tokens[$i - 1][0] ?? null, [T_DOUBLE_COLON], true)) {
				$pendingClass = true;
			}
			if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$contexts[] = $pendingClass ? 'class' : 'block';
				$pendingClass = false;
			} elseif ($text === '}') {
				array_pop($contexts);
			}
			$attribute = self::attributeState($attribute, $id, $text);
			if (in_array($id, self::NAMES, true)) {
				$attributeName = $attribute === [1, 0] && in_array($tokens[$i - 1][1], ['#[', ','], true);
				$stream[] = [$this->name($tokens, $i, $scope, $renames, $references, $attributeName), $line];
				continue;
			}
			if ($id === T_CONSTANT_ENCAPSED_STRING) {
				$stream[] = [$this->string($text, $renames, $references, $line), $line];
				continue;
			}
			// Only the open and close tags carry layout whitespace; every other token is content and stays intact.
			$stream[] = ['T:' . (in_array($id, [T_OPEN_TAG, T_CLOSE_TAG], true) ? trim($text) : $text), $line];
		}
		return new NormalizedSource($stream, $references);
	}

	/**
	 * Tokenizes the source and drops whitespace and comments.
	 *
	 * @param string $code PHP source
	 * @return list<array{0:int|null,1:string,2:int}> id (null for a single character), text, line
	 */
	private function significant(string $code): array {
		$tokens = [];
		$line = 1;
		foreach (token_get_all($code) as $token) {
			if (is_array($token)) {
				$line = $token[2];
				if (!in_array($token[0], self::SKIPPED, true)) {
					$tokens[] = [$token[0], $token[1], $line];
				}
				continue;
			}
			$tokens[] = [null, $token, $line];
		}
		return $tokens;
	}

	/**
	 * Normalizes one name token.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i position of the name
	 * @param ImportScope $scope namespace and imports in force
	 * @param array<string,string> $renames old FQCN => new FQCN
	 * @param list<array{0:string,1:int}> $references collects every class name referred to, with its line
	 * @param bool $attributeName whether the name is the class of an attribute (`#[Name(...)]`)
	 * @return string `C:<fqcn>` for a class reference, `T:<text>` otherwise
	 */
	private function name(array $tokens, int $i, ImportScope $scope, array $renames, array &$references, bool $attributeName): string {
		[$id, $text, $line] = $tokens[$i];
		$previous = $tokens[$i - 1] ?? [null, '', 0];
		$next = $tokens[$i + 1][1] ?? '';
		if (in_array($previous[0], self::MEMBER_PREFIXES, true) || in_array(strtolower($text), self::RESERVED, true)) {
			return 'T:' . $text;
		}
		if ($next === ':' && in_array($previous[1], ['(', ','], true)) {
			return 'T:' . $text;
		}
		if ($next === '(' && $previous[0] !== T_NEW && !$attributeName) {
			return 'T:' . $scope->resolveFunction($text);
		}
		$class = $scope->resolveClass($text);
		if ($id === T_STRING && !$attributeName && !$scope->isImported($text) && !$this->index->declares($class)) {
			// Kept for the stale-reference check: a same-namespace name whose class moved away resolves to nothing.
			$references[] = [$class, $line];
			return 'T:' . $scope->resolveConstant($text);
		}
		$references[] = [$class, $line];
		return 'C:' . ($renames[$class] ?? $class);
	}

	/**
	 * Normalizes a constant string literal: a class name by its value (renamed when the map moves it), anything else
	 * by its exact source text.
	 *
	 * @param string $text the literal with its quotes
	 * @param array<string,string> $renames old FQCN => new FQCN
	 * @param list<array{0:string,1:int}> $references collects the FQCN the string names, if any
	 * @param int $line line of the literal
	 * @return string `S=<fqcn>` for a class name, `S:<literal>` otherwise
	 */
	private function string(string $text, array $renames, array &$references, int $line): string {
		$value = PhpStringLiteral::value($text);
		if (preg_match(self::FQCN, $value) !== 1) {
			return 'S:' . $text;
		}
		$lead = str_starts_with($value, '\\') ? '\\' : '';
		$class = ltrim($value, '\\');
		$references[] = [$class, $line];
		return 'S=' . $lead . ($renames[$class] ?? $class);
	}

	/**
	 * Tracks whether the tokens are inside an attribute group and at which depth.
	 *
	 * @param array{0:int,1:int}|null $state bracket and parenthesis depth inside the current `#[...]`, null outside
	 * @param int|null $id token id
	 * @param string $text token text
	 * @return array{0:int,1:int}|null the state after this token
	 */
	private static function attributeState(?array $state, ?int $id, string $text): ?array {
		if ($id === T_ATTRIBUTE) {
			return [1, 0];
		}
		if ($state === null) {
			return null;
		}
		[$brackets, $parens] = $state;
		$brackets += match ($text) { '[' => 1, ']' => -1, default => 0 };
		$parens += match ($text) { '(' => 1, ')' => -1, default => 0 };
		return $brackets === 0 ? null : [$brackets, $parens];
	}

}
