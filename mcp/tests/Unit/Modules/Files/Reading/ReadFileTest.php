<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Reading;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Files\Reading\FileReader;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Tools\ReadFile;
use OCA\Mcp\Tests\Support\Files\FilesReadFixture;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadFileTest extends FilesReadFixture {
    private function read(string $path): array {
        $tool = new ReadFile(new FileReader($this->root, $this->extractor, $this->access, $this->ocr, $this->visibility));
        return $tool->execute(['path' => $path], new ToolContext('alice'));
    }

    public function testTextAndMetadataRemainSeparateBlocks(): void {
        $result = $this->read('//Notes//readme.md');
        self::assertCount(2, $result['content']);
        self::assertSame(['type' => 'text', 'text' => "# Notes\né"], $result['content'][0]);
        $meta = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['path', 'etag', 'size', 'mime', 'access'], array_keys($meta));
        self::assertSame('/Notes/readme.md', $meta['path']);
        self::assertSame('e102', $meta['etag']);
        self::assertSame(10, $meta['size']);
        self::assertSame('text/markdown', $meta['mime']);
        self::assertSame('personal', $meta['access']['scope']);
        self::assertSame([], $this->tree->ops);
    }

    public static function hiddenPaths(): array {
        return [['/alice/files/Notes/readme.md'], ['/alice/files/Notes']];
    }

    #[DataProvider('hiddenPaths')]
    public function testHiddenFilesAndAncestorsAreRefused(string $hidden): void {
        $this->visibility->setHiddenTagIds([9]);
        $this->tags[(string)$this->tree->nodes[$hidden]['id']] = ['9'];
        $this->expectException(ToolFailure::class);
        $this->read('/Notes/readme.md');
    }

    public function testDeniedReadNeverExtractsContent(): void {
        $this->tree->nodes['/alice/files/Notes/readme.md']['readable'] = false;
        try {
            $this->read('/Notes/readme.md');
            self::fail('Expected refusal');
        } catch (ToolFailure) {
            self::assertSame([], $this->tree->opened);
        }
    }

    public function testImageNoticeReflectsOcrAvailability(): void {
        $this->tree->addFile('/alice/files/scan.jpg', "\xFF\xD8", 'image/jpeg');
        foreach ([false, true] as $active) {
            $this->ocrActive = $active;
            $result = $this->read('/scan.jpg');
            self::assertSame($active ? ReadingMessages::noTextOcrActive() : ReadingMessages::noTextOcrInactive(), $result['content'][0]['text']);
            $meta = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($meta['text_layer']);
            self::assertSame($active, $meta['ocr_active']);
            self::assertSame($result['content'][0]['text'], $meta['notice']);
        }
        self::assertSame([], $this->tree->ops);
    }
}
