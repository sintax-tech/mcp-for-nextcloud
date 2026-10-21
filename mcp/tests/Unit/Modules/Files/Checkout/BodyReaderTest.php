<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Files\Checkout;

use OCA\Mcp\Modules\Files\Checkout\Http\BodyReader;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

final class BodyReaderTest extends TestCase {
    public function testStoredBodyIsRemovedEvenWhenTheConsumerThrows(): void {
        self::assertTrue(class_exists(BodyReader::class), 'HTTP body lifetime needs a focused owner');
        $path = tempnam(sys_get_temp_dir(), 'mcp-body-test');
        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturn($path);
        $reader = new class($temp) extends BodyReader {
            protected function inputStream() {
                $stream = fopen('php://memory', 'w+');
                fwrite($stream, 'abc');
                rewind($stream);
                return $stream;
            }
        };
        try {
            $reader->withStoredBody(3, static function (string $stored): never {
                self::assertSame('abc', file_get_contents($stored));
                throw new \RuntimeException('consumer failed');
            });
            self::fail('Consumer failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('consumer failed', $e->getMessage());
        } finally {
            self::assertFileDoesNotExist($path);
            if (file_exists($path)) { unlink($path); }
        }
    }
}
