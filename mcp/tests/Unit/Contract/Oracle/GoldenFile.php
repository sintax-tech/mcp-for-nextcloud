<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\TestCase;

/**
 * One golden JSON file of the v1.2.3 oracle: the value observed now is encoded the same way every time and compared
 * with the committed file, byte for byte.
 *
 * Regeneration is explicit: with `MCP_GOLDEN_UPDATE=1` in the environment the file is written instead of compared
 * (and the test still passes, so one run rewrites every golden). Without it a missing file fails, never creates
 * itself, and a different value fails with a {@see LineDiff} of the two encodings.
 */
final class GoldenFile {
	/** Environment variable that switches from comparing to rewriting the golden files. */
	public const UPDATE_ENV = 'MCP_GOLDEN_UPDATE';

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * Compares $value with the golden file, or rewrites the file in update mode.
	 *
	 * @param TestCase $test the running test, for its assertions
	 * @param string $path absolute path of the golden file
	 * @param mixed $value value observed now; encoded with {@see self::encode()}
	 * @return void
	 */
	public static function assertMatches(TestCase $test, string $path, mixed $value): void {
		$actual = self::encode($value);
		if (self::updating()) {
			if (!is_dir(dirname($path))) {
				mkdir(dirname($path), 0775, true);
			}
			file_put_contents($path, $actual);
			$test->addToAssertionCount(1);
			return;
		}
		$test->assertFileExists($path, 'Golden file missing; run with ' . self::UPDATE_ENV . '=1 to create it, then review the diff');
		$expected = (string)file_get_contents($path);
		if ($expected === $actual) {
			$test->addToAssertionCount(1);
			return;
		}
		$test->fail('Golden mismatch: ' . basename($path) . ' (rerun with ' . self::UPDATE_ENV . "=1 only for an approved change)\n"
			. LineDiff::render($expected, $actual));
	}

	/**
	 * The one encoding of every golden file: pretty JSON, slashes and Unicode kept literal, a final newline. An empty
	 * object stays `{}` and an empty list `[]`, as the endpoint encodes them.
	 *
	 * @param mixed $value JSON-encodable value
	 * @return string the file content
	 */
	public static function encode(mixed $value): string {
		return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			| JSON_PRESERVE_ZERO_FRACTION) . "\n";
	}

	/** @return bool whether this run rewrites the golden files */
	public static function updating(): bool {
		return getenv(self::UPDATE_ENV) === '1';
	}
}
