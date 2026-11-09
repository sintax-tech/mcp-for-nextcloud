<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Images\ImageSearchHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\Lock\LockedException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\TagNotFoundException;

/** Regression coverage for unresolved filters and backend failures in files_image_search. */
final class FilesImageSearchTest extends FilesToolsTestCase {
	/** @return void A missing tag must not masquerade as a successful empty search. */
	public function testMissingTagFails(): void {
		$this->tagManager->method('getAllTags')->willReturn([]);
		$this->tagMapper->expects(self::never())->method('getObjectIdsForTags');
		self::assertTagNotFound(fn () => $this->tool('files_image_search', ['tag' => 'missing']));
	}

	/** @return void An invisible tag answers exactly like a missing tag. */
	public function testInvisibleTagFailsWithoutDisclosingIt(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag(false)]);
		$this->tagMapper->expects(self::never())->method('getObjectIdsForTags');
		self::assertTagNotFound(fn () => $this->tool('files_image_search', ['tag' => 'holiday']));
	}

	/** @return void A visible, empty tag remains a legitimate successful empty search. */
	public function testExistingTagWithoutObjectsReturnsEmpty(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
		$this->tagMapper->method('getObjectIdsForTags')->willReturn([]);
		self::assertSame([], $this->json('files_image_search', ['tag' => 'holiday']));
	}

	/** @return void A tag attached only to non-images still has no matching images. */
	public function testExistingTagWithoutImagesReturnsEmpty(): void {
		$id = $this->tree->addFile('/alice/files/report.txt', 'text', 'text/plain');
		$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
		$this->tagMapper->method('getObjectIdsForTags')->willReturn([(string)$id]);
		self::assertSame([], $this->json('files_image_search', ['tag' => 'holiday']));
	}

	/** @return void Mapper outages fail with a safe message, including non-Exception errors. */
	public function testMapperFailureIsExplicit(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
		$this->tagMapper->method('getObjectIdsForTags')->willThrowException(new \Error('private backend details'));
		self::assertSame('Failed to search images in Nextcloud.', $this->failure('files_image_search', ['tag' => 'holiday']));
	}

	/** @return void A failed tag lookup is operational failure rather than a missing filter. */
	public function testTagManagerFailureIsExplicit(): void {
		$this->tagManager->method('getAllTags')->willThrowException(new \RuntimeException('private database details'));
		self::assertSame('Failed to search images in Nextcloud.', $this->failure('files_image_search', ['tag' => 'holiday']));
	}

	/** @return void A tag removed between lookup and mapping also reports the missing filter. */
	public function testTagRemovedDuringSearchFails(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
		$this->tagMapper->method('getObjectIdsForTags')->willThrowException(new TagNotFoundException());
		self::assertTagNotFound(fn () => $this->tool('files_image_search', ['tag' => 'holiday']));
	}

	/** @return void A scope failure during query filtering must retain its 503 semantics. */
	public function testPolicyFailureWithoutTagPropagates(): void {
		$this->assertVisibilityFailure(false, new PolicyUnavailable());
	}

	/** @return void A scope failure during tag filtering must retain its 503 semantics. */
	public function testPolicyFailureWithTagPropagates(): void {
		$this->assertVisibilityFailure(true, new PolicyUnavailable());
	}

	/** @return void A query encountering a lock must retain the registry's lock response. */
	public function testLockedNodeWithoutTagPropagates(): void {
		$this->assertVisibilityFailure(false, new LockedException('/private/file'));
	}

	/** @return void A tagged file encountering a lock must retain the registry's lock response. */
	public function testLockedNodeWithTagPropagates(): void {
		$this->assertVisibilityFailure(true, new LockedException('/private/file'));
	}

	/** @return void Wrapped backend failures leave one log entry containing only class and code. */
	public function testBackendFailureLogsSafeDiagnostics(): void {
		$root = $this->searchRoot();
		$root->method('search')->willThrowException(new \Error('private backend details', 41));
		$this->logger->expects(self::once())->method('error')->with('Image search failed: {tool}: {exception_detail}', [
			'app' => 'mcp',
			'tool' => 'files_image_search',
			'exception_class' => \Error::class,
			'exception_detail' => 'Error (code 41)',
		]);
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage('Failed to search images in Nextcloud.');
		$this->handler()->execute($root, 'alice', ['limit' => 25], static fn (Folder $root, string $userId, File $file): array => ['name' => $file->getName()]);
	}

	/** @return void An unavailable mount skips only its node and preserves other matching images. */
	public function testBrokenMountSkipsOnlyBrokenNode(): void {
		$root = $this->searchRoot();
		$file = $this->searchFile();
		$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
		$this->tagMapper->method('getObjectIdsForTags')->willReturn(['11', '22']);
		$root->expects(self::exactly(2))->method('getById')->willReturnCallback(static function (int $id) use ($file): array {
			if ($id === 11) {
				throw new \Error('private mount failure');
			}
			return [$file];
		});
		$this->logger->expects(self::never())->method('error');
		self::assertSame([['name' => 'holiday.jpg']], $this->handler()->execute($root, 'alice', ['tag' => 'holiday', 'limit' => 25], static fn (Folder $root, string $userId, File $file): array => ['name' => $file->getName()]));
	}

	/** @return void The per-node fallback must never swallow an unavailable policy. */
	public function testPolicyFailureDuringNodeLookupPropagates(): void {
		$this->assertNodeLookupFailure(new PolicyUnavailable());
	}

	/** @return void The per-node fallback must never swallow a lock. */
	public function testLockDuringNodeLookupPropagates(): void {
		$this->assertNodeLookupFailure(new LockedException('/private/file'));
	}

	/**
	 * Runs both search paths with a failure at the per-file visibility boundary.
	 *
	 * @param bool $tagged Whether to resolve file IDs through the tag mapper.
	 * @param \Throwable $failure Policy or lock failure that must propagate unchanged.
	 * @return void
	 */
	private function assertVisibilityFailure(bool $tagged, \Throwable $failure): void {
		$root = $this->searchRoot();
		$file = $this->searchFile();
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->expects(self::once())->method('isVisible')->with($file)->willThrowException($failure);
		$this->logger->expects(self::never())->method('error');
		$args = ['limit' => 25];
		if ($tagged) {
			$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
			$this->tagMapper->method('getObjectIdsForTags')->willReturn(['22']);
			$root->method('getById')->willReturn([$file]);
			$args['tag'] = 'holiday';
		} else {
			$root->method('search')->willReturn([$file]);
		}
		$this->expectExceptionObject($failure);
		$this->handler($guard)->execute($root, 'alice', $args, static fn (Folder $root, string $userId, File $file): array => ['name' => $file->getName()]);
	}

	/**
	 * Asserts that the node lookup fallback preserves exceptional policy and lock responses.
	 *
	 * @param \Throwable $failure Failure raised by getById.
	 * @return void
	 */
	private function assertNodeLookupFailure(\Throwable $failure): void {
		$root = $this->searchRoot();
		$this->tagManager->method('getAllTags')->willReturn([$this->tag()]);
		$this->tagMapper->method('getObjectIdsForTags')->willReturn(['22']);
		$root->method('getById')->willThrowException($failure);
		$this->logger->expects(self::never())->method('error');
		$this->expectExceptionObject($failure);
		$this->handler()->execute($root, 'alice', ['tag' => 'holiday', 'limit' => 25], static fn (Folder $root, string $userId, File $file): array => ['name' => $file->getName()]);
	}

	/**
	 * @param FilesAccessGuard|null $guard Guard controlling per-file visibility.
	 * @return ImageSearchHandler Handler with minimal metadata to isolate exception boundaries.
	 */
	private function handler(?FilesAccessGuard $guard = null): ImageSearchHandler {
		return new ImageSearchHandler(
			new \OCA\Mcp\Modules\Files\Images\ImageTagSearch($this->tagManager, $this->tagMapper, $this->users, $this->logger, $guard),
			new \OCA\Mcp\Modules\Files\Images\ImageQuerySearch($this->createMock(IDBConnection::class), $this->users, $guard),
			$this->logger,
			$guard,
		);
	}

	/** @return Folder Readable root whose file lookup and search can be fault-injected. */
	private function searchRoot(): Folder {
		$root = $this->createMock(Folder::class);
		$root->method('isReadable')->willReturn(true);
		$root->method('getPath')->willReturn('/alice/files');
		return $root;
	}

	/** @return File Readable image that reaches the per-file visibility check. */
	private function searchFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(22);
		$file->method('getPath')->willReturn('/alice/files/holiday.jpg');
		$file->method('getName')->willReturn('holiday.jpg');
		$file->method('getMimetype')->willReturn('image/jpeg');
		$file->method('isReadable')->willReturn(true);
		$file->method('getMTime')->willReturn(123);
		return $file;
	}

	/**
	 * Asserts that the search fails with the tag-not-found message.
	 *
	 * @param callable(): mixed $search The search call that must fail.
	 * @return void
	 */
	public static function assertTagNotFound(callable $search): void {
		try {
			$search();
		} catch (ToolFailure $failure) {
			self::assertSame('Tag not found in Nextcloud.', $failure->getMessage());
			return;
		}
		self::fail('An unresolved tag must fail explicitly.');
	}

	/**
	 * @param bool $visible Whether the authenticated user may see the tag.
	 * @return ISystemTag A tag named holiday.
	 */
	private function tag(bool $visible = true): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('77');
		$tag->method('getName')->willReturn('holiday');
		$tag->method('isUserVisible')->willReturn($visible);
		return $tag;
	}
}
