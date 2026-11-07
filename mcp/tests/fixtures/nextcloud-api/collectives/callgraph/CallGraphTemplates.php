<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Resolves inherited template returns only from explicit declarations and explicit parent arguments. */
final class CallGraphTemplates {
	/**
	 * @param CallGraphIndex $index pinned class declarations
	 * @param string $receiver concrete receiving class
	 * @param string $owner class declaring the inherited method
	 * @param string $method case-insensitive method name
	 * @return array{bool,?string} whether a template return applies, and its proved substitution
	 */
	public static function result(CallGraphIndex $index, string $receiver, string $owner, string $method): array {
		$node = $index->classes[$owner]['methods'][strtolower($method)]['node'] ?? null;
		$text = $node?->getDocComment()?->getText() ?? '';
		preg_match_all('/@template(?:-covariant|-contravariant)?\s+(\w+)/', $index->classes[$owner]['doc'] ?? '', $templates);
		if (!preg_match('/@(?:psalm-return|phpstan-return|return)\s+(\S+)/', $text, $match)) {
			return [false, null];
		}
		// Prefer the more precise static-analysis contract over an erased @return/native type.
		if (preg_match('/@(?:psalm|phpstan)-return\s+(\S+)/', $text, $precise)) {
			$match = $precise;
		}
		$return = $match[1];
		$used = array_filter($templates[1], static fn (string $t): bool => preg_match('/\b' . preg_quote($t, '/') . '\b/', $return) === 1);
		if ($used === []) {
			return [false, null];
		}
		$bindings = self::bindings($index, $receiver, $owner, []);
		foreach ($used as $template) {
			if (!isset($bindings[$template])) {
				return [true, null];
			}
			$return = preg_replace('/\b' . preg_quote($template, '/') . '\b/', '\\' . $bindings[$template], $return);
		}
		return [true, CallGraphNames::docType($index->imports($owner), substr($owner, 0, (int)strrpos($owner, '\\') + 1), $owner, $return)];
	}

	/**
	 * @param CallGraphIndex $index declarations
	 * @param string $class current class in the inheritance walk
	 * @param string $target declaring ancestor whose template parameters are needed
	 * @param array<string,string> $known current class's substituted template arguments
	 * @param list<string> $seen cycle guard
	 * @return array<string,string> ancestor template names to resolved class names
	 */
	private static function bindings(CallGraphIndex $index, string $class, string $target, array $known, array $seen = []): array {
		if ($class === $target) {
			return $known;
		}
		if (in_array($class, $seen, true)) {
			return [];
		}
		$info = $index->classes[$class] ?? [];
		preg_match_all('/@(?:template-extends|extends)\s+([\w\\\\]+)<([^>]+)>/', $info['doc'] ?? '', $extensions, PREG_SET_ORDER);
		foreach ($info['parents'] ?? [] as $parent) {
			$values = [];
			foreach ($extensions as [, $base, $args]) {
				$resolved = CallGraphNames::docType($index->imports($class), substr($class, 0, (int)strrpos($class, '\\') + 1), $class, $base);
				if ($resolved !== $parent) {
					continue;
				}
				preg_match_all('/@template(?:-covariant|-contravariant)?\s+(\w+)/', $index->classes[$parent]['doc'] ?? '', $parameters);
				foreach (explode(',', $args) as $position => $arg) {
					$arg = trim($arg);
					$type = $known[$arg] ?? CallGraphNames::docType($index->imports($class), substr($class, 0, (int)strrpos($class, '\\') + 1), $class, $arg);
					if (isset($parameters[1][$position]) && $type !== null) {
						$values[$parameters[1][$position]] = $type;
					}
				}
			}
			$result = self::bindings($index, $parent, $target, $values, [...$seen, $class]);
			if ($result !== []) {
				return $result;
			}
		}
		return [];
	}
}
