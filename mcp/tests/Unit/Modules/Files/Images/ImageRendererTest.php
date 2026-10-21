<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Files\Images;

use OCA\Mcp\Modules\Files\Images\ImageRenderer;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCP\IPreview;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ImageRendererTest extends TestCase {
    public function testOriginalFallbackNeedsNoSearchOrTagServices(): void {
        self::assertTrue(class_exists(ImageRenderer::class), 'Image rendering must be independently constructible');
        $tree = new FakeTree($this);
        $tree->addFile('/alice/files/photo.png', 'png-bytes', 'image/png');
        $preview = $this->createMock(IPreview::class);
        $preview->method('getPreview')->willThrowException(new \RuntimeException('provider unavailable'));
        $renderer = new ImageRenderer($preview, $this->createMock(LoggerInterface::class));
        self::assertSame(['png-bytes', 'image/png', 1568], $renderer->renderPreview($tree->node('/alice/files/photo.png'), 1568, 100));
        self::assertSame([], $tree->ops);
    }
}
