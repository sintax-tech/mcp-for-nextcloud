<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Tests\Unit\Tools\ApprovedDeleteSink;
use OCA\Mcp\Tests\Unit\Tools\FileDeletionGuard;
use PHPUnit\Framework\Assert;

/** Preserves the historical undo guards while excluding only the exact new FD-1 sink. */
final class DeleteSourceBoundary {
	/**
	 * @param string $base Production Files directory.
	 * @return void
	 */
	public static function verify(string $base): void {
		$sources = FileDeletionGuard::sources($base);
		Assert::assertNotEmpty($sources);
		$found = [];
		foreach (ApprovedDeleteSink::remaining($sources) as $path) {
			$source = (string)file_get_contents($path);
			if (!str_contains($source, '->delete()')) {
				continue;
			}
			$found[] = basename($path);
			Assert::assertSame('UndoBatchHandler.php', basename($path));
			Assert::assertSame(1, substr_count($source, '->delete()'));
			Assert::assertStringContainsString('$this->context->reorganization->byDepth($batch->dirs)', $source);
			Assert::assertStringContainsString('!$folder instanceof Folder || !$folder->isDeletable() || $folder->getDirectoryListing() !== []', $source);
		}
		Assert::assertSame(['UndoBatchHandler.php'], $found);
	}
}
