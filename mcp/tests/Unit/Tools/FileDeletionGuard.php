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
		$files = ApprovedDeleteSink::remaining($files);
		$owner = realpath($undoOwner);
		foreach ($files as $file) {
			$source = (string)file_get_contents($file);
			if ($owner !== false && realpath($file) === $owner && str_contains($source, '->delete()')) {
				$undo = strpos($source, 'function undoBatch');
				$first = strpos($source, '->delete()');
				Assert::assertNotFalse($undo, 'a remoção precisa estar dentro de undoBatch');
				Assert::assertGreaterThan($undo, $first, 'a remoção está antes de undoBatch');
				Assert::assertSame(1, preg_match_all('/->delete\(\)/', $source), 'a remoção do lote é uma só, dentro do desfazer');
				continue;
			}
			Assert::assertDoesNotMatchRegularExpression('/->delete\(\)/', $source,
				basename($file) . ' calls Node::delete(); the MCP removes no file and no folder');
		}
	}
}
