<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk;

use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Modules\Talk\TalkToolCatalog;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\TalkErrors;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TalkCompositionTest extends TestCase
{
    public function testMetadataAndReadPreviewNeverResolveFactories(): void
    {
        $factories = [];
        foreach (TalkToolCatalog::classes() as $class) {
            $factories[$class::definition()->name] = static fn() => throw new \RuntimeException('factory ran');
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $module = new TalkModule(
            $factories,
            new ResultBoundary(new TalkErrors(), $logger),
            new PreviewBoundary(new TalkErrors(), $logger),
        );
        self::assertCount(8, $module->definitions());
        foreach (['talk_read_messages', 'unknown'] as $name) {
            try {
                $module->preview($name, [], 'alice');
                self::fail('Expected refusal');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(\OCA\Mcp\Modules\Talk\Execution\ErrorMessages::unknownTool(), $e->getMessage());
            }
        }
    }

    public function testFactoryFailureIsMappedAndLoggedOnceForEachEntryPoint(): void
    {
        $factories = [];
        foreach (TalkToolCatalog::classes() as $class) {
            $factories[$class::definition()->name] = static fn() => throw new \RuntimeException('container internals');
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error');
        $module = new TalkModule(
            $factories,
            new ResultBoundary(new TalkErrors(), $logger),
            new PreviewBoundary(new TalkErrors(), $logger),
        );
        $result = $module->call('talk_reply', [], 'alice');
        self::assertTrue($result['isError']);
        self::assertStringNotContainsString('container internals', json_encode($result));
        $this->expectException(ToolFailure::class);
        $module->preview('talk_reply', [], 'bob');
    }
}
