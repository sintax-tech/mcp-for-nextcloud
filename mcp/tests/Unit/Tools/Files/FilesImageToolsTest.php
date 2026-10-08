<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Common\CommonMessages;

use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\ImageTools;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Constants;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use OCP\SystemTag\ISystemTag;

/**
 * files_image_view, files_images_view and files_image_search as the Files tools expose them: read-only
 * previews rendered through IPreview, batch budget enforcement, and image search filtered through
 * ISystemTagObjectMapper.
 */
final class FilesImageToolsTest extends FilesToolsTestCase {
    public function testImageViewReturnsBase64PreviewAndMetadata(): void {
        $this->tree->addFile('/alice/files/Photos/sun.jpg', str_repeat('J', 400), 'image/jpeg');
        $this->preparePreview('image/jpeg', str_repeat('J', 200));

        $result = $this->tool('files_image_view', ['path' => '/Photos/sun.jpg']);

        self::assertSame('image', $result['content'][0]['type']);
        self::assertSame('image/jpeg', $result['content'][0]['mimeType']);
        self::assertSame(str_repeat('J', 200), base64_decode($result['content'][0]['data']));
        $metadata = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/Photos/sun.jpg', $metadata['path']);
        self::assertSame('image/jpeg', $metadata['mime']);
        self::assertSame(ImageTools::DEFAULT_MAX_SIZE, $metadata['effective_max_size']);
        self::assertSame('personal', $metadata['access']['scope']);
    }

    public function testImageViewIncludesDimensionsWhenAvailable(): void {
        $im = imagecreatetruecolor(120, 80);
        ob_start();
        imagejpeg($im);
        $jpegBytes = (string)ob_get_clean();
        imagedestroy($im);

        $this->tree->addFile('/alice/files/Photos/dim.jpg', $jpegBytes, 'image/jpeg');
        $this->preparePreview('image/jpeg', 'preview-bytes');

        $result = $this->tool('files_image_view', ['path' => '/Photos/dim.jpg']);
        $metadata = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['width' => 120, 'height' => 80], $metadata['dimensions']);
    }

    public function testImageViewFallsBackToOriginalWhenPreviewFailsAndMimeIsSafe(): void {
        $this->tree->addFile('/alice/files/Photos/tiny.png', str_repeat('P', 300), 'image/png');
        $this->previewManager->method('getPreview')->willThrowException(new \RuntimeException('no provider'));

        $result = $this->tool('files_image_view', ['path' => '/Photos/tiny.png']);

        self::assertSame('image', $result['content'][0]['type']);
        self::assertSame(str_repeat('P', 300), base64_decode($result['content'][0]['data']));
    }

    public function testImageViewFailsFriendlyOnUnsupportedMime(): void {
        $this->tree->addFile('/alice/files/Documentos/notes.txt', 'hello', 'text/plain');

        self::assertSame(FilesMessages::notAnImage(),
            $this->failure('files_image_view', ['path' => '/Documentos/notes.txt']));
    }

    public function testImageViewRejectsOriginalOverByteLimitWhenPreviewMissing(): void {
        $this->tree->addFile('/alice/files/Photos/big.png', str_repeat('P', 3 * 1024 * 1024), 'image/png');
        $this->previewManager->method('getPreview')->willThrowException(new \RuntimeException('no provider'));

        self::assertSame(FilesMessages::imagePreviewTooLarge(),
            $this->failure('files_image_view', ['path' => '/Photos/big.png']));
    }

    public function testImagesViewHonorsBatchByteBudgetAndReportsSkipped(): void {
        // Originals are large so the per-image fallback cannot bail the second and third out.
        $big = str_repeat('A', 500 * 1024);
        $this->tree->addFile('/alice/files/Photos/a.jpg', $big, 'image/jpeg');
        $this->tree->addFile('/alice/files/Photos/b.jpg', $big, 'image/jpeg');
        $this->tree->addFile('/alice/files/Photos/c.jpg', $big, 'image/jpeg');
        // Each preview is 600 KB; batch budget set to 800 KB so only the first fits.
        $this->sizedPreview(600 * 1024);
        $this->config->app['mcp'] = ['image_batch_max_bytes' => (string)(800 * 1024)];

        $result = $this->tool('files_images_view', ['paths' => ['/Photos/a.jpg', '/Photos/b.jpg', '/Photos/c.jpg']]);

        // Count image content items (ignore metadata text items)
        $imageItems = array_values(array_filter($result['content'], fn (array $c): bool => $c['type'] === 'image'));
        self::assertCount(1, $imageItems);
        $summary = json_decode($result['content'][array_key_last($result['content'])]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $summary['returned']);
        self::assertCount(2, $summary['skipped']);
    }

    public function testImagesViewRefusesWhenPathsAndFolderBothProvided(): void {
        $this->tree->addFile('/alice/files/Photos/a.jpg', 'A', 'image/jpeg');

        self::assertSame(FilesMessages::imagesConflict(),
            $this->failure('files_images_view', ['paths' => ['/Photos/a.jpg'], 'folder' => '/Photos']));
    }

    public function testImagesViewRefusesWhenNoTargetProvided(): void {
        self::assertSame(FilesMessages::imagesNoTarget(),
            $this->failure('files_images_view', []));
    }

    public function testImageSearchFiltersByMimeFolderAndDate(): void {
        $this->tree->addFolder('/alice/files/Photos');
        // Image with mtime deep in the past.
        $this->tree->addFile('/alice/files/Photos/old.jpg', 'o', 'image/jpeg');
        $this->tree->nodes['/alice/files/Photos/old.jpg']['mtime'] = strtotime('2024-01-01T00:00:00Z');
        $this->tree->addFile('/alice/files/Photos/new.jpg', 'n', 'image/jpeg');
        $this->tree->nodes['/alice/files/Photos/new.jpg']['mtime'] = strtotime('2026-05-01T00:00:00Z');
        $this->tree->addFile('/alice/files/Photos/plan.pdf', 'p', 'application/pdf');
        $this->tree->addFile('/alice/files/Documentos/ignore.jpg', 'i', 'image/jpeg');

        $out = $this->json('files_image_search', [
            'folder' => '/Photos',
            'modified_after' => '2026-01-01',
        ]);

        self::assertCount(1, $out);
        self::assertSame('/Photos/new.jpg', $out[0]['path']);
        self::assertSame('image/jpeg', $out[0]['mime']);
    }

    public function testImageSearchByUnknownTagReturnsEmpty(): void {
        $this->tree->addFile('/alice/files/Photos/a.jpg', 'A', 'image/jpeg');
        $this->tagManager->method('getAllTags')->willReturn([]);

        self::assertSame([], $this->json('files_image_search', ['tag' => 'nothing']));
    }

    public function testImageSearchByKnownTagIntersectsResults(): void {
        $taggedId = $this->tree->addFile('/alice/files/Photos/tagged.jpg', 'T', 'image/jpeg');
        $this->tree->addFile('/alice/files/Photos/untagged.jpg', 'U', 'image/jpeg');

        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn('42');
        $tag->method('getName')->willReturn('vacation');
        $tag->method('isUserVisible')->willReturn(true);
        $this->tagManager->method('getAllTags')->willReturn([$tag]);
        $this->tagManager->method('getTagsByIds')->willReturn([$tag]);
        $this->tagMapper->method('getObjectIdsForTags')->willReturn([(string)$taggedId]);
        $this->tagMapper->method('getTagIdsForObjects')->willReturn([(string)$taggedId => ['42']]);

        $out = $this->json('files_image_search', ['tag' => 'vacation']);

        self::assertCount(1, $out);
        self::assertSame('/Photos/tagged.jpg', $out[0]['path']);
        self::assertSame(['vacation'], $out[0]['tags']);
    }

    public function testImageSearchRefusesInvalidDate(): void {
        self::assertSame(FilesMessages::invalidDate('not-a-date'),
            $this->failure('files_image_search', ['modified_after' => 'not-a-date']));
    }

    public function testImageSearchHidesFilesWithoutReadPermission(): void {
        $this->tree->addFile('/alice/files/Photos/hidden.jpg', 'H', 'image/jpeg', [
            'readable' => false,
            'permissions' => Constants::PERMISSION_SHARE,
        ]);

        self::assertSame([], $this->json('files_image_search', ['folder' => '/Photos']));
    }

    public function testImageToolsAreReadOnly(): void {
        $definitions = array_column($this->module->definitions(), null, 'name');
        self::assertSame('read', $definitions['files_image_view']['operation']);
        self::assertSame('read', $definitions['files_images_view']['operation']);
        self::assertSame('read', $definitions['files_image_search']['operation']);
    }


    public function testImageSearchQueryPushdownWithLimitReturnsNewestMatchingItems(): void {
        $this->tree->addFolder('/alice/files/Photos');
        for ($i = 1; $i <= 5; $i++) {
            $this->tree->addFile("/alice/files/Photos/pic{$i}.jpg", "data{$i}", 'image/jpeg');
            $this->tree->nodes["/alice/files/Photos/pic{$i}.jpg"]['mtime'] = 1000 * $i;
        }
        $this->tree->addFile('/alice/files/Photos/other.txt', 'text', 'text/plain');
        $this->tree->nodes['/alice/files/Photos/other.txt']['mtime'] = 9999;

        $out = $this->json('files_image_search', [
            'folder' => '/Photos',
            'query' => 'pic',
            'limit' => 2,
        ]);

        self::assertCount(2, $out);
        self::assertSame('/Photos/pic5.jpg', $out[0]['path']);
        self::assertSame('/Photos/pic4.jpg', $out[1]['path']);

        $lastSearch = end($this->tree->searches);
        self::assertNotNull($lastSearch);
        self::assertSame(2, $lastSearch->getLimit());
        self::assertNotEmpty($lastSearch->getOrder());
    }

    public function testImageSearchTagOnNodeOutsideUserViewDoesNotAppear(): void {
        $this->tree->addFolder('/alice/files/Photos');
        $this->tree->addFile('/alice/files/Photos/local.jpg', 'L', 'image/jpeg');
        $foreignId = 99999;

        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn('77');
        $tag->method('getName')->willReturn('outside-tag');
        $tag->method('isUserVisible')->willReturn(true);
        $this->tagManager->method('getAllTags')->willReturn([$tag]);
        $this->tagMapper->method('getObjectIdsForTags')->willReturn([(string)$foreignId]);

        $out = $this->json('files_image_search', [
            'folder' => '/Photos',
            'tag' => 'outside-tag',
        ]);

        self::assertSame([], $out);
    }

    public function testImageSearchTagCeilingEnforcesMaximumAndLogsWarning(): void {
        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn('88');
        $tag->method('getName')->willReturn('popular');
        $tag->method('isUserVisible')->willReturn(true);
        $this->tagManager->method('getAllTags')->willReturn([$tag]);

        $ids = array_map('strval', range(1, 2005));
        $this->tagMapper->method('getObjectIdsForTags')->willReturn($ids);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Image tag search truncated: tag has more objects than ceiling',
                $this->callback(fn (array $ctx): bool =>
                    $ctx['tag'] === 'popular' && $ctx['count'] === 2005 && $ctx['ceiling'] === 2000
                )
            );

        $out = $this->json('files_image_search', ['tag' => 'popular']);
        self::assertIsArray($out);
    }

    public function testInvisibleTagCannotBeSearchedAndIsOmittedFromResults(): void {
        $id = $this->tree->addFile('/alice/files/Photos/tagged.jpg', 'T', 'image/jpeg');

        $visibleTag = $this->createMock(ISystemTag::class);
        $visibleTag->method('getId')->willReturn('10');
        $visibleTag->method('getName')->willReturn('visible-tag');
        $visibleTag->method('isUserVisible')->willReturn(true);

        $invisibleTag = $this->createMock(ISystemTag::class);
        $invisibleTag->method('getId')->willReturn('20');
        $invisibleTag->method('getName')->willReturn('invisible-tag');
        $invisibleTag->method('isUserVisible')->willReturn(false);

        $this->tagManager->method('getAllTags')->willReturn([$visibleTag, $invisibleTag]);
        $this->tagManager->method('getTagsByIds')->willReturn([$visibleTag, $invisibleTag]);
        $this->tagMapper->method('getTagIdsForObjects')->willReturn([(string)$id => ['10', '20']]);

        $out = $this->json('files_image_search', ['tag' => 'invisible-tag']);
        self::assertSame([], $out);

        $out2 = $this->json('files_image_search', ['folder' => '/Photos']);
        self::assertCount(1, $out2);
        self::assertSame(['visible-tag'], $out2[0]['tags']);
    }

    public function testSelectFromFolderSearchesAsAuthenticatedUserOnSharedFolder(): void {
        $this->tree->addFolder('/alice/files/SharedFolder', ['scope' => 'shared']);
        $this->tree->addFile('/alice/files/SharedFolder/shared.jpg', 'S', 'image/jpeg', ['scope' => 'shared']);
        $this->preparePreview('image/jpeg', 'preview');

        $out = $this->tool('files_images_view', ['folder' => '/SharedFolder']);
        self::assertSame('image', $out['content'][0]['type']);

        $lastSearch = end($this->tree->searches);
        self::assertNotNull($lastSearch);
        self::assertSame('alice', $lastSearch->getUser()->getUID());
    }

    public function testMtimeFormatIsIso8601UtcAcrossTools(): void {
        $mtime = 1790000000;
        $iso = gmdate('Y-m-d\TH:i:s\Z', $mtime);
        $this->tree->addFile('/alice/files/Photos/clock.jpg', 'C', 'image/jpeg');
        $this->tree->nodes['/alice/files/Photos/clock.jpg']['mtime'] = $mtime;
        $this->preparePreview('image/jpeg', 'preview');

        $view = $this->tool('files_image_view', ['path' => '/Photos/clock.jpg']);
        $metaView = json_decode($view['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($iso, $metaView['mtime']);

        $views = $this->tool('files_images_view', ['paths' => ['/Photos/clock.jpg']]);
        $metaViews = json_decode($views['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($iso, $metaViews['mtime']);

        $search = $this->json('files_image_search', ['folder' => '/Photos']);
        self::assertSame($iso, $search[0]['mtime']);
    }

    /** Returns the single IPreview preview with the given bytes and mimetype. */
    public function testHiddenImageIsHiddenFromViewAndSearch(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();

        $this->tree->addFolder('/alice/files/Photos');
        $imgId = $this->tree->addFile('/alice/files/Photos/secret.jpg', 'img', 'image/jpeg');
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$imgId => ['999']]);

        // files_image_view fails not found
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_image_view', ['path' => '/Photos/secret.jpg']));

        // files_images_view skips hidden image as not found in summary
        $views = $this->tool('files_images_view', ['paths' => ['/Photos/secret.jpg']]);
        $summary = json_decode(end($views['content'])['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/Photos/secret.jpg', $summary['skipped'][0]['path']);
        $this->assertSame(CommonMessages::notFound(), $summary['skipped'][0]['reason']);

        // files_image_search omits hidden image
        $results = $this->json('files_image_search', ['folder' => '/Photos']);
        $paths = array_column($results, 'path');
        $this->assertNotContains('/Photos/secret.jpg', $paths);
    }

    /**
     * A visible /Photos with one hidden image and one visible, behind the guard of the tag 999.
     *
     * @return array{secret:int, visible:int} the ids of the two images
     */
    private function photosWithAHiddenImage(): array {
        $guardTags = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $guardTags);
        $this->setUp();
        $ids = [
            'secret' => $this->tree->addFile('/alice/files/Photos/secret.jpg', 'S', 'image/jpeg'),
            'visible' => $this->tree->addFile('/alice/files/Photos/visible.jpg', 'V', 'image/jpeg'),
        ];
        $guardTags->method('getTagIdsForObjects')->willReturnCallback(static fn (array $objects): array => array_combine($objects,
            array_map(static fn ($id): array => (string)$id === (string)$ids['secret'] ? ['999'] : [], $objects)));
        return $ids;
    }

    /** @return list<string> the paths files_images_view returned images of */
    private function viewedPaths(array $result): array {
        $paths = [];
        foreach ($result['content'] as $item) {
            if ($item['type'] === 'text') {
                $meta = json_decode($item['text'], true, 512, JSON_THROW_ON_ERROR);
                if (isset($meta['path'])) {
                    $paths[] = $meta['path'];
                }
            }
        }
        return $paths;
    }

    /** Picking a folder must not hand out the bytes of a hidden image that sits in it, next to a visible one. */
    public function testImagesViewOfAFolderNeverReturnsAHiddenImage(): void {
        $this->photosWithAHiddenImage();
        $this->preparePreview('image/jpeg', 'preview');
        $result = $this->tool('files_images_view', ['folder' => '/Photos']);
        $this->assertSame(['/Photos/visible.jpg'], $this->viewedPaths($result), 'a visível vem, a oculta não');
        $this->assertCount(1, array_filter($result['content'], static fn (array $item): bool => $item['type'] === 'image'));
        $this->assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** A tag that marks a hidden image and a visible one finds only the visible one. */
    public function testImageSearchByTagNeverReturnsAHiddenImage(): void {
        $ids = $this->photosWithAHiddenImage();
        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn('30');
        $tag->method('getName')->willReturn('ferias');
        $tag->method('isUserVisible')->willReturn(true);
        $this->tagManager->method('getAllTags')->willReturn([$tag]);
        $this->tagMapper->method('getObjectIdsForTags')->willReturn([(string)$ids['secret'], (string)$ids['visible']]);

        $this->assertSame(['/Photos/visible.jpg'], array_column($this->json('files_image_search', ['tag' => 'ferias']), 'path'));
    }

    /** A hidden folder answers exactly like one that does not exist: an empty list would say it is there. */
    public function testImageSearchInAHiddenFolderAnswersLikeAMissingOne(): void {
        $guardTags = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $guardTags);
        $this->setUp();
        $hidden = $this->tree->addFolder('/alice/files/Secreta');
        $this->tree->addFile('/alice/files/Secreta/foto.jpg', 'F', 'image/jpeg');
        $guardTags->method('getTagIdsForObjects')->willReturnCallback(static fn (array $objects): array => array_combine($objects,
            array_map(static fn ($id): array => (string)$id === (string)$hidden ? ['999'] : [], $objects)));

        $missing = $this->failure('files_image_search', ['folder' => '/NaoExiste']);
        $this->assertSame(CommonMessages::notFound(), $missing);
        $this->assertSame($missing, $this->failure('files_image_search', ['folder' => '/Secreta']));
        $this->assertSame($missing, $this->failure('files_image_search', ['folder' => '/Secreta', 'tag' => 'qualquer']));
    }

    private function preparePreview(string $mime, string $bytes): void {
        $simple = $this->createMock(ISimpleFile::class);
        $simple->method('getMimeType')->willReturn($mime);
        $simple->method('getContent')->willReturn($bytes);
        $simple->method('getSize')->willReturn(strlen($bytes));
        $this->previewManager->method('getPreview')->willReturn($simple);
    }

    /** Returns a preview whose bytes have the given size, regardless of max_size requested. */
    private function sizedPreview(int $size): void {
        $simple = $this->createMock(ISimpleFile::class);
        $simple->method('getMimeType')->willReturn('image/jpeg');
        $simple->method('getContent')->willReturnCallback(fn (): string => str_repeat('X', $size));
        $simple->method('getSize')->willReturn($size);
        $this->previewManager->method('getPreview')->willReturn($simple);
    }
}
