<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Stmt;

/** Declared scalar, class and docblock types shared by methods, functions and properties. */
final class CallGraphSignatures {
	/** @param CallGraphIndex $index class-name context */
	public function __construct(private CallGraphIndex $index) {
	}

	/**
	 * @param Node|null $type a declared type
	 * @param string|null $class FQCN of the code
	 * @return string|null the class it names, '' for a declared scalar, null for an unresolved union or type
	 */
	public function declared(?Node $type, ?string $class): ?string {
		if ($type instanceof Node\NullableType) {
			$type = $type->type;
		}
		if ($type instanceof Node\UnionType) {
			$named = array_values(array_filter($type->types, static fn (Node $t): bool => !($t instanceof Node\Identifier && $t->toLowerString() === 'null')));
			$type = count($named) === 1 ? $named[0] : null;
		}
		return $type instanceof Node\Name ? $this->index->name($class, $type)
			: ($type instanceof Node\Identifier && in_array($type->toLowerString(), ['string', 'int', 'float', 'bool', 'void', 'never', 'false', 'true', 'null'], true) ? '' : null);
	}

	/**
	 * @param Stmt\ClassMethod|Stmt\Function_ $fn a method or function
	 * @param callable(string):?string $doc reads a docblock type
	 * @param string|null $class FQCN of the code
	 * @return array{0:?string, 1:array<string,string>} its stated return and parameter types
	 */
	public function signature(Node $fn, callable $doc, ?string $class): array {
		$text = $fn->getDocComment()?->getText() ?? '';
		$returns = $this->declared($fn->returnType, $class) ?? (preg_match('/@return\s+(\S+)/', $text, $m) ? $doc($m[1]) : null);
		$params = [];
		preg_match_all('/@param\s+(\S+)\s+(?:\.\.\.)?\$(\w+)/', $text, $ps, PREG_SET_ORDER);
		foreach ($ps as [, $type, $param]) {
			if (($type = $doc($type)) !== null) {
				$params[$param] = $type;
			}
		}
		foreach ($fn->params as $param) {
			if (($type = $this->declared($param->type, $class)) !== null && is_string($param->var->name)) {
				$params[$param->var->name] = $type;
			}
		}
		return [$returns, $params];
	}

}
