<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\ToolFailure;
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

	/**
	 * Shared assertion for the existing image metadata regression test.
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
