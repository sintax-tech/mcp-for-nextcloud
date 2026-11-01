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
 * temporary files and puts back every destination this call already replaced, so the whole set is published or
 * none of it is. Rename is atomic on the same filesystem, which the sibling temporary file guarantees.
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
			$previous = [];
			foreach ($temps as $path => $temp) {
				$previous[$path] = self::snapshot($path);
			}
			$published = [];
			foreach ($temps as $path => $temp) {
				if (is_dir($path) || !rename($temp, $path)) {
					throw new RuntimeException("cannot replace $path");
				}
				unset($temps[$path]);
				$published[] = $path;
			}
		} catch (Throwable $error) {
			foreach ($temps as $temp) {
				if (is_file($temp)) {
					unlink($temp);
				}
			}
			foreach ($published ?? [] as $path) {
				self::restore($path, $previous[$path] ?? null);
			}
			throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
		}
	}

	/**
	 * The bytes to put back if a later rename fails.
	 *
	 * A missing path and anything that is not a regular file (a directory blocking the rename) have nothing to
	 * restore. The file is read before any rename, so a published destination can be returned to these bytes.
	 *
	 * @param string $path destination
	 * @return string|null the previous bytes, or null when there was no file to restore
	 * @throws RuntimeException when the existing file cannot be read
	 */
	private static function snapshot(string $path): ?string {
		if (!is_file($path)) {
			return null;
		}
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			throw new RuntimeException("cannot replace $path");
		}
		return $bytes;
	}

	/**
	 * Puts one destination back to the bytes it had before this call renamed it.
	 *
	 * @param string $path destination already replaced
	 * @param string|null $bytes previous bytes, or null when the path did not exist
	 * @return void
	 * @throws RuntimeException when the previous bytes cannot be written back
	 */
	private static function restore(string $path, ?string $bytes): void {
		if ($bytes === null) {
			if (is_file($path)) {
				unlink($path);
			}
			return;
		}
		$written = file_put_contents($path, $bytes, LOCK_EX);
		if ($written !== strlen($bytes)) {
			throw new RuntimeException("cannot replace $path");
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
