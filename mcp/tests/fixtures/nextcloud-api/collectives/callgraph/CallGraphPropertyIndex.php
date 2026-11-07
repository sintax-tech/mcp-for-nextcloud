<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Property container declarations constrain writes without inventing scalar element types. */
final class CallGraphPropertyIndex {
	/**
	 * @param Stmt\ClassLike $node pinned declaration
	 * @param string $class fully-qualified class
	 * @param CallGraphSignatures $signatures native declaration reader
	 * @param callable(string):?string $doc class/import-aware doc type parser
	 * @return array{array<string,string>,array<string,?string>} inferred values and strictly declared contracts
	 */
	public static function read(Stmt\ClassLike $node, string $class, CallGraphSignatures $signatures, callable $doc): array {
		$props = $declared = [];
		if ($node instanceof Stmt\Enum_) {
			$props = $node->scalarType === null ? ['name' => ''] : ['name' => '', 'value' => ''];
		}
		foreach ($node->getProperties() as $property) {
			$text = $property->getDocComment()?->getText() ?? '';
			$native = $signatures->declared($property->type, $class);
			$type = $native ?? (preg_match('/@var\s+(\S+(?:\s+[^>\r\n]*>)?)/', $text, $match) ? $doc($match[1]) : null);
			$array = $property->type instanceof Node\Identifier && $property->type->toLowerString() === 'array';
			foreach ($property->props as $prop) {
				$name = $prop->name->name;
				$declared[$name] = $type ?? ($array ? CallGraphArrayTypes::make([''], [null]) : null);
				$value = $type ?? CallGraphLiterals::type($prop->default);
				// A mutable, untyped array cannot retain only its initializer's element evidence.
				foreach ((new NodeFinder())->findInstanceOf($node->getMethods(), Expr\Assign::class) as $write) {
					$target = $write->var instanceof Expr\ArrayDimFetch ? $write->var->var : $write->var;
					if (($target instanceof Expr\PropertyFetch && $target->var instanceof Expr\Variable && $target->var->name === 'this'
						|| $target instanceof Expr\StaticPropertyFetch) && $target->name instanceof Node\Identifier && $target->name->name === $name && $type === null) {
						$value = $array || CallGraphArrayTypes::read($value) !== null ? CallGraphArrayTypes::make([''], [null]) : null;
					}
				}
				$value ??= $array ? CallGraphArrayTypes::make([''], [null]) : null;
				if ($value !== null) {
					$props[$name] = $value;
				}
			}
		}
		return [$props, $declared];
	}
}
