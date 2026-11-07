<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Generic docblock containers and unions are parsed by nesting depth, never by field-name guesses. */
final class CallGraphDocs {
	/**
	 * @param string $text type expression
	 * @param string $separator top-level separator
	 * @return list<string> pieces, preserving nested generic/shape expressions
	 */
	public static function split(string $text, string $separator): array {
		$depth = 0;
		$start = 0;
		$result = [];
		for ($i = 0; $i < strlen($text); $i++) {
			$depth += in_array($text[$i], ['<', '{', '('], true) ? 1 : (in_array($text[$i], ['>', '}', ')'], true) ? -1 : 0);
			if ($text[$i] === $separator && $depth === 0) {
				$result[] = trim(substr($text, $start, $i - $start));
				$start = $i + 1;
			}
		}
		$result[] = trim(substr($text, $start));
		return $result;
	}

	/**
	 * @param string $doc type expression
	 * @param callable(string):?string $class resolves a non-container class token
	 * @return string|null explicit type or a generic container whose unproved elements remain null
	 */
	public static function type(string $doc, callable $class): ?string {
		$doc = trim($doc);
		$parts = self::split(ltrim($doc, '?'), '|');
		if (count($parts) > 1) {
			return CallGraphArrayTypes::pack(array_map(static fn (string $p): ?string => self::type($p, $class), $parts));
		}
		if (str_ends_with($doc, '[]')) {
			return CallGraphArrayTypes::make([''], CallGraphArrayTypes::members([self::type(substr($doc, 0, -2), $class)]));
		}
		if (preg_match('/^(array|list|iterable)<(.+)>$/', $doc, $match)) {
			$args = self::split($match[2], ',');
			return CallGraphArrayTypes::make(count($args) === 2 ? [self::type($args[0], $class)] : [''], CallGraphArrayTypes::members([self::type(end($args), $class)]));
		}
		if (in_array(strtolower($doc), ['array', 'list', 'iterable'], true)) {
			return CallGraphArrayTypes::make([''], [null]);
		}
		if (in_array(strtolower($doc), ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'scalar', 'null', 'void', 'never', 'false', 'true', 'resource', 'array-key'], true)) {
			return CallGraphScalarTypes::declared($doc);
		}
		return in_array(strtolower($doc), ['mixed', 'object'], true) ? null : $class($doc);
	}
}
