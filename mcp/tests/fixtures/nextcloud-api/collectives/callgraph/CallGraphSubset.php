<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Exact node classes form a closed syntax boundary; supporting a parent class never admits a new subclass. */
final class CallGraphSubset {
	/** @var array<class-string, true>|null explicit expression/statement node classes */
	private static ?array $allowed = null;

	/**
	 * @param Node $node a node in a reached body
	 * @return bool whether the exact node type has an explicit model
	 */
	public static function allows(Node $node): bool {
		self::$allowed ??= array_fill_keys(json_decode(file_get_contents(dirname(__DIR__) . '/collectives-ast-subset.json'), true, 512, JSON_THROW_ON_ERROR), true);
		return isset(self::$allowed[get_class($node)]);
	}

	/**
	 * @param Node $node any node of a reached body
	 * @param callable(Node, string):void $miss reports a file/line rejection
	 * @param callable(Expr):list<?string>|null $typeOf evidence required for array unpacking
	 * @return void rejects unlisted syntax and modifiers not represented by the exact node class
	 */
	public static function check(Node $node, callable $miss, ?callable $typeOf = null): void {
		if (($node instanceof Expr || $node instanceof Stmt) && !self::allows($node)) {
			$miss($node, 'AST node outside the closed subset: ' . get_class($node));
		}
		if (($node instanceof Node\Arg || $node instanceof Node\ArrayItem) && $node->unpack) {
			$types = $typeOf === null ? [null] : $typeOf($node->value);
			if ($types === [] || array_filter($types, static fn (?string $t): bool => $t === null || !str_ends_with($t, '[]')) !== []) {
				$miss($node, 'spread without a proven array type');
			}
		}
		if ($node instanceof Node\Arg && $node->name !== null) {
			$miss($node, 'named arguments are outside the closed subset');
		}
		if (($node instanceof Node\Arg || $node instanceof Node\ArrayItem) && $node->byRef
			|| $node instanceof Expr\Variable && !is_string($node->name)
			|| $node instanceof Stmt\Foreach_ && $node->byRef
			|| $node instanceof Node\Param && ($node->byRef || $node->variadic)
			|| $node instanceof Node\ClosureUse && $node->byRef) {
			$miss($node, 'AST modifier outside the closed subset: references, spread or dynamic variables');
		}
	}
}
