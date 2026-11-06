<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\Assert;

/** The FD-1 exception is restricted to the exact approved handler, never a basename or whole namespace. */
final class ApprovedDeleteSink {
	/**
	 * @param list<string> $files Recursive inventory of Files sources.
	 * @return list<string> Sources still subject to the historical no-deletion guard.
	 */
	public static function remaining(array $files): array {
		$approved = realpath(\OCA\Mcp\Tests\Architecture\Inventory\FilesSources::sink(dirname(__DIR__, 3), 'OCA\\Mcp\\Modules\\Files\\Handler\\DeleteFileHandler'));
		return array_values(array_filter($files, static function (string $file) use ($approved): bool {
			if ($approved === false || realpath($file) !== $approved) {
				return true;
			}
			$source = (string)file_get_contents($file);
			Assert::assertSame(1, preg_match_all('/->delete\(\)/', $source));
			Assert::assertGreaterThan(strpos($source, 'function handle'), strpos($source, '->delete()'));
			return false;
		}));
	}
}
