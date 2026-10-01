<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

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

    /** Returns the single IPreview preview with the given bytes and mimetype. */
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
