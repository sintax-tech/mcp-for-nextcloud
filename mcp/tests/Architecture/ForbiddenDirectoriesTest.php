<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Inventory\DirectoryRules;

/** Only app folders are in scope; tests may retain their historical organization. */
final class ForbiddenDirectoriesTest extends ArchitectureCase {
	public function testAppFoldersNameTheWork(): void {
		$paths = array_keys(self::tree()->files);
		foreach (['lib', 'src', 'templates', 'appinfo'] as $root) {
			foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::tree()->root . '/' . $root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) { if ($file->isDir()) { $paths[] = substr($file->getPathname(), strlen(self::tree()->root) + 1); } }
		}
		self::assertSame([], DirectoryRules::problems($paths));
	}
	public function testForbiddenSegmentsAndSingleWorkFolderFail(): void {
		foreach (['Services', 'Helpers', 'Utils', 'Handler'] as $directory) { self::assertNotSame([], DirectoryRules::problems(['lib/Modules/Notes/' . $directory . '/X.php'])); }
		self::assertSame([], DirectoryRules::problems(['lib/Modules/Example/Example.php']));
		foreach (['Reading', 'Creation', 'Editing', 'Deletion', 'Sharing', 'Moving', 'Reorganization', 'Checkout', 'Writing', 'Searching', 'Extraction', 'Locating', 'Composing', 'Scheduling', 'Sending', 'Attaching', 'Batching', 'Messaging', 'Quoting', 'Replying', 'Completion', 'Planning', 'Selftest', 'Admission', 'Archiving'] as $verb) {
			self::assertNotSame([], DirectoryRules::problems(['lib/Modules/Example/' . $verb . '/X.php']));
			self::assertSame([], DirectoryRules::problems(['lib/Modules/Example/' . $verb . '/X.php', 'lib/Modules/Example/' . $verb . '/Y.php']));
		}
		self::assertNotSame([], DirectoryRules::problems(['lib/Modules/Files/ContentCommit.php']));
		foreach (['FilesModule', 'FilesToolCatalog', 'FilesRegistration', 'FilesCallContext', 'FilesPreviewContext'] as $name) { self::assertSame([], DirectoryRules::problems(['lib/Modules/Files/' . $name . '.php'])); }
		self::assertSame([], DirectoryRules::problems(['lib/Modules/People/Integration/Guests.php', 'lib/Modules/People/Tools/SearchUsers.php']));
		self::assertSame([], DirectoryRules::problems(['lib/Platform/Handler/Example.php']));
	}
}
