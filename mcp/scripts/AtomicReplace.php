<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

use RuntimeException;
use Throwable;

/**
 * Replaces bundle files by writing a temporary sibling and renaming it.
 *
 * The destination is not opened for writing until every temporary file is complete. A failure deletes the
 * temporary files and leaves every destination as it was. Rename is atomic on the same filesystem, which the
 * sibling temporary file guarantees.
 */
final class AtomicReplace {
	/**
	 * Replaces each path with the given bytes.
	 *
	 * @param array<string, string> $files absolute path => full contents
	 * @param int|null $interruptAfter test seam: throw after this many temporary files have been staged, before
	 *                                  any rename, so a caller can prove a destination is not left partial
	 * @return void
	 * @throws RuntimeException when a file cannot be staged or renamed, or when the build is interrupted
	 */
	public static function replaceAll(array $files, ?int $interruptAfter = null): void {
		$temps = [];
		try {
			foreach ($files as $path => $contents) {
				if ($interruptAfter !== null && count($temps) >= $interruptAfter) {
					throw new RuntimeException('build interrupted');
				}
				$temps[$path] = self::stage($path, $contents);
			}
			foreach ($temps as $path => $temp) {
				if (!rename($temp, $path)) {
					throw new RuntimeException("cannot replace $path");
				}
				unset($temps[$path]);
			}
		} catch (Throwable $error) {
			foreach ($temps as $temp) {
				if (is_file($temp)) {
					unlink($temp);
				}
			}
			throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
		}
	}

	/**
	 * Writes the contents to a new temporary file in the destination directory.
	 *
	 * @param string $path destination
	 * @param string $contents bytes to publish
	 * @return string absolute path of the temporary file
	 * @throws RuntimeException when the directory is missing or the write is short
	 */
	private static function stage(string $path, string $contents): string {
		$dir = dirname($path);
		if (!is_dir($dir)) {
			throw new RuntimeException("cannot replace $path");
		}
		$temp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
		$written = file_put_contents($temp, $contents, LOCK_EX);
		if ($written !== strlen($contents)) {
			if (is_file($temp)) {
				unlink($temp);
			}
			throw new RuntimeException("cannot replace $path");
		}
		return $temp;
	}
}
