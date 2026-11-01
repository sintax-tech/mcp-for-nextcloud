<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Distinguishes a supported source root from a pure dirname of a node's runtime path. */
final class RuntimeDirname {
	/** Prevents instantiation of this static proof helper. */
	private function __construct() {
	}

	/**
	 * Keeps unknown variables and composed source roots refused; permits only the exact no-argument getPath call.
	 * The caller has already proved the dirname callable builtin, and normal token comparison preserves the operand.
	 *
	 * @param list<array{0:int|null,1:string,2:int}> $tokens significant tokens
	 * @param int $i dirname callable token
	 * @param string $path source file path
	 * @param ImportScope $scope current namespace and imports
	 * @param SourceTreeIndex $index source declarations
	 * @return string|null reason the expression cannot be proved independent of the source folder
	 */
	public static function unproved(array $tokens, int $i, string $path, ImportScope $scope, SourceTreeIndex $index): ?string {
		if (PathExpressions::fold($tokens, $i, $path, $scope, $index) !== null) {
			return null;
		}
		return RuntimeNodePath::unproved($tokens, $i);
	}
}
