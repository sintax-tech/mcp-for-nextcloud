<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Class names as the call graph reads them from source text: docblock types and the namespace of a tree. */
final class CallGraphNames {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param array<string, string> $imports the imports of the file
	 * @param string $namespace namespace of the code, with its trailing backslash
	 * @param string|null $self FQCN that self, static and $this mean
	 * @param string $doc a type written in a docblock
	 * @return string|null the FQCN, `FQCN[]` for an array of one class, or null when it names no single class
	 */
	public static function docType(array $imports, string $namespace, ?string $self, string $doc): ?string {
		$parts = array_values(array_diff(explode('|', ltrim($doc, '?')), ['null']));
		if (count($parts) !== 1) {
			return null;
		}
		[$type, $array] = [$parts[0], ''];
		if (preg_match('/^(?:list|array|iterable)<(?:[\w\\\\]+,\s*)?([\w\\\\]+)>$/', $type, $m) || preg_match('/^([\w\\\\]+)\[\]$/', $type, $m)) {
			[$type, $array] = [$m[1], '[]'];
		}
		if (in_array(strtolower($type), ['self', 'static', '$this'], true)) {
			return $self === null ? null : $self . $array;
		}
		if (preg_match('/^[A-Z]\w*$/', substr((string)strrchr('\\' . $type, '\\'), 1)) !== 1) {
			return null;
		}
		$head = explode('\\', ltrim($type, '\\'))[0];
		$fqcn = str_starts_with($type, '\\') ? ltrim($type, '\\')
			: (isset($imports[$head]) ? $imports[$head] . substr($type, strlen($head)) : $namespace . $type);
		return $fqcn . $array;
	}

	/**
	 * @param list<string> $classes FQCNs
	 * @return string the namespace they all are in, with its trailing backslash
	 */
	public static function commonNamespace(array $classes): string {
		$common = array_slice(explode('\\', $classes[0]), 0, -1);
		foreach ($classes as $class) {
			$parts = array_slice(explode('\\', $class), 0, -1);
			$i = 0;
			while ($i < count($common) && ($parts[$i] ?? null) === $common[$i]) {
				$i++;
			}
			$common = array_slice($common, 0, $i);
		}
		return implode('\\', $common) . '\\';
	}
}
