<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Inventory\FilesDeletionPolicy;
use OCA\Mcp\Tests\Unit\Tools\FileDeletionGuard;
use PHPUnit\Framework\AssertionFailedError;

/** Both current and future Files roots use the same node/backup/temp-path assertions. */
final class FilesDeletionControlsTest extends ArchitectureCase {
	public function testFutureNestedSinksCopiesBackupCleanupAndNodeUnlinkFail(): void {
		$root = sys_get_temp_dir() . '/mcp-re08-delete-' . bin2hex(random_bytes(6));
		mkdir($root . '/Modules/Files/Sharing/deep', 0700, true);
		$path = $root . '/Modules/Files/Sharing/deep/' . basename('/lib/Modules/Files/Handler/DeleteFileHandler.php');
		try {
			foreach ([
				['<?php function handle($node) { $node->delete(); }', 'node'],
				['<?php function handle($node) { $node->delete(); $node->delete(); }', 'node'],
				['<?php function cleanupBackups() {}', 'backup'],
				['<?php unlink($node->getPath());', 'unlink'],
			] as [$source, $rule]) {
				file_put_contents($path, $source);
				$paths = FileDeletionGuard::sources($root);
				self::assertSame([$path], $paths);
				try {
					match ($rule) { 'node' => FilesDeletionPolicy::assertNodes($paths, $root . '/Undo.php', $root . '/Approved.php'), 'backup' => FilesDeletionPolicy::assertBackups($paths), 'unlink' => FilesDeletionPolicy::assertTemporaryUnlinks($paths) };
					self::fail('Deletion mutant passed');
				} catch (AssertionFailedError $failure) { self::assertStringNotContainsString('Deletion mutant passed', $failure->getMessage()); }
			}
			file_put_contents($path, '<?php function handle($node) { $node->delete(); }');
			FilesDeletionPolicy::assertNodes([$path], $root . '/Undo.php', $path);
			file_put_contents($path, '<?php function undoBatch($folder) { $folder->delete(); }');
			FilesDeletionPolicy::assertNodes([$path], $path, $root . '/Approved.php');
			file_put_contents($path, '<?php $temporary = $manager->getTemporaryFile(); unlink($temporary);');
			FilesDeletionPolicy::assertTemporaryUnlinks([$path]);
		} finally { @unlink($path); rmdir(dirname($path)); rmdir(dirname($path, 2)); rmdir(dirname($path, 3)); rmdir(dirname($path, 4)); rmdir($root); }
	}
}
