<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A planted nested deletion must fail the same assertion that checks all real Files sources. */
final class FileDeletionGuardTest extends TestCase {
	/**
	 * Supply both nested source directories, including a colliding name that must not gain the undo exemption.
	 *
	 * @return iterable<string, array{string, string}> Subdirectory and fixture basename.
	 */
	public static function nestedSources(): iterable {
		yield 'handler' => ['Handler', 'Injected.php'];
		yield 'sharing' => ['Sharing', 'Injected.php'];
		yield 'nested FD-1-name collision' => ['Sharing', basename('/lib/Modules/Files/Deletion/DeleteFileHandler.php')];
		yield 'nested undo-name collision' => ['Handler', basename('/lib/Modules/Files/Reorganization/Reorganization.php')];
	}

	/**
	 * Plant a forbidden call in a disposable source tree and observe an actual PHPUnit assertion failure.
	 *
	 * @param string $directory Nested directory that the old glob missed.
	 * @param string $name Planted source filename.
	 * @return void
	 */
	#[DataProvider('nestedSources')]
	public function testNestedDeletionFailsTheProductionSourceAssertion(string $directory, string $name): void {
		$root = sys_get_temp_dir() . '/mcp-no-delete-' . bin2hex(random_bytes(8));
		$nested = $root . '/' . $directory;
		$file = $nested . '/' . $name;
		mkdir($nested, 0700, true);
		try {
			file_put_contents($file, '<?php function undoBatch($node) { $node->delete(); }');
			$sources = FileDeletionGuard::sources($root);
			self::assertSame([$file], $sources);
			$failure = null;
			try {
				FileDeletionGuard::assertNoNodeDeletion($sources, dirname(__DIR__, 3) . '/lib/Modules/Files/Reorganization/Reorganization.php');
			} catch (AssertionFailedError $error) {
				$failure = $error;
			}
			self::assertInstanceOf(AssertionFailedError::class, $failure, 'A nested deletion must fail the real source assertion.');
			self::assertStringContainsString($name . ' calls Node::delete()', $failure->getMessage());
		} finally {
			if (is_file($file)) {
				unlink($file);
			}
			rmdir($nested);
			rmdir($root);
		}
		self::assertDirectoryDoesNotExist($root);
	}
}
