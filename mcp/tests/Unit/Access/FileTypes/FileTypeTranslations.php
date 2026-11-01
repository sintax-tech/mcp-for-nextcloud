<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\FileTypes;

/** Reads both shipped translation formats independently of the caller's test folder. */
final class FileTypeTranslations {
	/** Prevents instantiation of the source reader. */
	private function __construct() {
	}

	/**
	 * @param string $locale shipped locale name
	 * @return array{json:string,javascript:string} unmodified catalog sources
	 */
	public static function read(string $locale): array {
		$root = dirname(__DIR__, 4) . '/l10n/';
		return [
			'json' => (string)file_get_contents($root . $locale . '.json'),
			'javascript' => (string)file_get_contents($root . $locale . '.js'),
		];
	}
}
