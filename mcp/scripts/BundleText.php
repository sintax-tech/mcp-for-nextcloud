<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

use RuntimeException;

/**
 * Concatenates manifest fragments into one classic script.
 *
 * Each fragment is its origin header, a newline and the file bytes. Between fragments the separator is `;\n`
 * immediately before the next header. There is no preamble and no trailing separator after the last fragment,
 * so the same sources always produce the same bytes.
 */
final class BundleText {
	/**
	 * The bundle for one manifest.
	 *
	 * @param string $root app directory
	 * @param list<string> $paths fragment paths relative to `src/`, in manifest order
	 * @return string the bundle, empty when the manifest names nothing
	 * @throws RuntimeException when a fragment that coverage already accepted cannot be read
	 */
	public static function render(string $root, array $paths): string {
		$chunks = [];
		foreach ($paths as $path) {
			$body = file_get_contents($root . '/src/' . $path);
			if ($body === false) {
				throw new RuntimeException("cannot read $path");
			}
			$chunks[] = "/* $path */\n" . $body;
		}
		return implode(";\n", $chunks);
	}
}
