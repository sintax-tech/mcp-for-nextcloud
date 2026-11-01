<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\Assert;

/** Recursive source inventory and the shared assertion protecting Files from node deletion. */
final class FileDeletionGuard {
	/**
	 * Collect every PHP source below the Files directory, including handlers and sharing.
	 *
	 * @param string $root Source root or an isolated temporary fixture directory.
	 * @return list<string> Sorted PHP paths, including all subdirectories.
	 */
	public static function sources(string $root): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Reject deletion outside the existing empty-folder removal in the canonical reorganization file.
	 *
	 * @param list<string> $files Sources returned by the recursive inventory.
	 * @param string $undoOwner The one canonical file containing the existing undoBatch allowance.
	 * @return void
	 * @throws \PHPUnit\Framework\AssertionFailedError When any source violates the deletion rule.
	 */
	public static function assertNoNodeDeletion(array $files, string $undoOwner): void {
		\OCA\Mcp\Tests\Architecture\Inventory\FilesDeletionPolicy::assertNodes($files, $undoOwner,
			\OCA\Mcp\Tests\Architecture\Inventory\FilesSources::sink(dirname(__DIR__, 3), \OCA\Mcp\Tools\Files\Handler\DeleteFileHandler::class));
	}
}
