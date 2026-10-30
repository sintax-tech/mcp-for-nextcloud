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
 * their import; a name imported both as class and as constant is compared as both. Functions and constants
 * declared at namespace level, and calls to them, are qualified with the namespace, so moving a file with a free
 * function fails (functions are not in the map). With the file's path, `__DIR__`/`__FILE__` expressions fold to
 * the exact root they yield ({@see PathExpressions}), keeping all suffix tokens; unproved path consumers and `__NAMESPACE__` are reported as
 * location-dependent, for the comparator to refuse when the move changes the folder or the namespace.
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

	/** Tokens after which a name is a member or label name, never a class. */
	private const MEMBER_PREFIXES = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_GOTO];

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
	 * @param string|null $path path of the file in the tree, relative to the app folder; null leaves `__DIR__` literal
	 * @return NormalizedSource the stream, the class names, strings and location-dependent constructs of the source
	 */
	public function normalize(string $code, array $renames = [], ?string $path = null): NormalizedSource {
		$tokens = $this->significant($code);
		$scope = new ImportScope();
		$stream = [];
		$references = [];
		$strings = PathSafety::references($tokens);
		$located = [];
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
			$folded = $path === null || ($attribute !== null && !in_array($id, [T_DIR, T_FILE], true)) ? null : PathExpressions::fold($tokens, $i, $path, $scope, $this->index);
			if ($folded !== null) {
				$stream[] = ['P:' . $folded[0] . '|' . $folded[2], $line];
				$i = $folded[1];
				continue;
			}
			if ($id === T_NS_C) {
				$located[] = [$line, '__NAMESPACE__'];
			}
			if ($path !== null && ($reason = PathSafety::unproved($tokens, $i, $path, $scope, $this->index)) !== null) {
				$located[] = [$line, $reason];
			}
			if (in_array($id, self::NAMES, true)) {
				$attributeName = $attribute === [1, 0] && in_array($tokens[$i - 1][1], ['#[', ','], true);
				$stream[] = [$this->name($tokens, $i, $scope, $renames, $references, $attributeName, end($contexts) !== 'class'), $line];
				continue;
			}
			if ($id === T_CONSTANT_ENCAPSED_STRING) {
				$stream[] = [$this->string($text, $renames, $references, $line), $line];
				continue;
			}
			// Only the open and close tags carry layout whitespace; every other token is content and stays intact.
			$stream[] = ['T:' . (in_array($id, [T_OPEN_TAG, T_CLOSE_TAG], true) ? trim($text) : $text), $line];
		}
		return new NormalizedSource($stream, $references, $strings, $located);
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
	 * @param bool $outsideClass whether the name is outside a class body (a function or constant there is namespaced)
	 * @return string `C:` class, `F:` function, `K:` constant, `T:` anything else
	 */
	private function name(array $tokens, int $i, ImportScope $scope, array $renames, array &$references, bool $attributeName, bool $outsideClass): string {
		[$id, $text, $line] = $tokens[$i];
		$previous = $tokens[$i - 1] ?? [null, '', 0];
		$next = $tokens[$i + 1][1] ?? '';
		if (in_array($previous[0], [T_FUNCTION, T_CONST], true)) {
			// A declaration: namespaced outside a class, a member name inside one.
			return $outsideClass ? ($previous[0] === T_FUNCTION ? 'F:' : 'K:') . $scope->qualify($text) : 'T:' . $text;
		}
		if (in_array($previous[0], self::MEMBER_PREFIXES, true) || in_array(strtolower($text), self::RESERVED, true)) {
			return 'T:' . $text;
		}
		if ($next === ':' && in_array($previous[1], ['(', ','], true)) {
			return 'T:' . $text;
		}
		if ($next === '(' && $previous[0] !== T_NEW && !$attributeName) {
			return 'F:' . $scope->resolveFunction($text, $this->index);
		}
		$class = $scope->resolveClass($text);
		if ($id === T_STRING && !$attributeName && $scope->hasConstant($text) && $scope->isImported($text)) {
			// Imported both as class and as constant: compare both, so redirecting either one fails.
			$references[] = [$class, $line];
			return 'K:' . ($renames[$class] ?? $class) . '|' . $scope->resolveConstant($text, $this->index);
		}
		if ($id === T_STRING && !$attributeName && !$scope->isImported($text) && !$this->index->declares($class)) {
			// Kept for the stale-reference check: a same-namespace name whose class moved away resolves to nothing.
			$references[] = [$class, $line];
			return 'K:' . $scope->resolveConstant($text, $this->index);
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
