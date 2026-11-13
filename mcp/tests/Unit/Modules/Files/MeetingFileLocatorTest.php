<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Reading\MeetingFileLocator;
use OCA\Mcp\Platform\Meetings\LocalInstanceLink;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** A file link becomes one path only when every alias is a visible file of this instance. */
final class MeetingFileLocatorTest extends TestCase {
	/**
	 * A foreign host is not fetched and is not turned into a file id of this instance.
	 *
	 * @return void
	 */
	public function testAForeignHostNeverTouchesTheFolder(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->expects($this->never())->method('assertScopeReadable');
		$locator = $this->locator($root, $guard);

		self::assertNull($locator->placed('alice', 'https://evil.example/f/123'));
		self::assertNull($locator->placed('alice', 'https://outro.example/apps/files/?fileid=9'));
	}

	/**
	 * A DAV link of another user is refused before the actor's folder is opened.
	 *
	 * @return void
	 */
	public function testADavLinkOfAnotherUserIsNotOpened(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->expects($this->never())->method('assertScopeReadable');
		$locator = $this->locator($root, $guard);

		self::assertNull($locator->placed('alice', 'https://cloud.example/remote.php/dav/files/bob/Notes/a.md'));
	}

	/**
	 * One invisible alias refuses the id, even when another alias of the same id is a readable file.
	 *
	 * @return void
	 */
	public function testOneInvisibleAliasRefusesTheFile(): void {
		$hidden = $this->file(15);
		$shown = $this->file(15);
		$home = $this->home([15 => [$hidden, $shown]]);
		$root = $this->root($home);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(static fn (Node $node): bool => $node !== $hidden);

		self::assertNull($this->locator($root, $guard)->placed('alice', '/f/15'));
	}

	/**
	 * A folder is not a file, so the dossier does not quote it.
	 *
	 * @return void
	 */
	public function testAFolderIsNotAFile(): void {
		$directory = $this->createMock(Folder::class);
		$directory->method('getId')->willReturn(15);
		$directory->method('isReadable')->willReturn(true);
		$directory->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
		$home = $this->home([15 => [$directory]]);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturn(true);

		self::assertNull($this->locator($this->root($home), $guard)->placed('alice', 'https://cloud.example/apps/files/?fileid=15'));
	}

	/**
	 * `/f/<id>` and `?fileid=` resolve to the same path, timestamp and canonical URL.
	 *
	 * @return void
	 */
	public function testAFileIdAndAFileQueryResolveToTheCanonicalLink(): void {
		$home = $this->home([15 => [$this->file(15)]]);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturn(true);
		$locator = $this->locator($this->root($home), $guard);
		$expected = ['path' => '/Notes/a.md', 'id' => 15, 'mtime' => 1710000000, 'url' => 'https://cloud.example/f/15'];

		self::assertSame($expected, $locator->placed('alice', '/f/15'));
		self::assertSame($expected, $locator->placed('alice', 'https://cloud.example/apps/files/?fileid=15'));
		self::assertSame('/Notes/a.md', $locator->path('alice', '15'));
	}

	/**
	 * A scope that cannot be read propagates. It is not reported as a missing file.
	 *
	 * @return void
	 */
	public function testAnUnreadableScopePropagates(): void {
		$home = $this->home([15 => [$this->file(15)]]);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willThrowException(new PolicyUnavailable('x'));

		$this->expectException(PolicyUnavailable::class);
		$this->locator($this->root($home), $guard)->placed('alice', '/f/15');
	}

	/**
	 * @param IRootFolder $root the actor's files
	 * @param FilesAccessGuard $guard scope and tags
	 * @return MeetingFileLocator a locator of cloud.example
	 */
	private function locator(IRootFolder $root, FilesAccessGuard $guard): MeetingFileLocator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example/');
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $arguments = []): string => $route === 'files.View.showFile'
				? 'https://cloud.example/f/' . $arguments['fileid']
				: '',
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static function (string $key, mixed $default = ''): mixed {
			return match ($key) {
				'trusted_domains' => ['cloud.example'],
				'overwrite.cli.url' => 'https://cloud.example',
				default => $default,
			};
		});
		return new MeetingFileLocator($root, $guard, new LocalInstanceLink($urls, $config), $urls);
	}

	/**
	 * @param Folder $home the actor's folder
	 * @return IRootFolder a root that opens that folder for alice
	 */
	private function root(Folder $home): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		return $root;
	}

	/**
	 * @param array<int, list<Node>> $byId nodes of each file id
	 * @return Folder the actor's folder
	 */
	private function home(array $byId): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('getById')->willReturnCallback(static fn (int $id): array => $byId[$id] ?? []);
		$folder->method('getRelativePath')->willReturn('/Notes/a.md');
		return $folder;
	}

	/**
	 * @param int $id the file id
	 * @return File a readable file
	 */
	private function file(int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('isReadable')->willReturn(true);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getMTime')->willReturn(1710000000);
		$file->method('getPath')->willReturn('/alice/files/Notes/a.md');
		return $file;
	}
}
