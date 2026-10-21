<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Logs\Analysis;

use OCA\Mcp\Modules\Logs\Analysis\AnalyzeEntries;
use OCA\Mcp\Modules\Logs\Reading\{LogQuery, LogReader, LogScanner};
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class AnalyzeEntriesTest extends TestCase {
    public function testAnalysisUsesFreshTimeAndTheSameWindow(): void {
        self::assertTrue(class_exists(AnalyzeEntries::class), 'The analysis capability is missing');
        $config = new InMemoryConfig();
        $writer = $this->createMockForIntersectionOfInterfaces([\OCP\Log\IWriter::class, \OCP\Log\IFileBased::class]);
        $writer->expects(self::once())->method('getEntries')->with(5000, 0)->willReturnCallback(function () use ($config): array {
            $config->system['logdateformat'] = 'd.m.Y H:i:s';
            return [(object)['level' => 3, 'app' => 'core', 'time' => '17.10.2026 12:00:00', 'message' => 'synthetic-secret', 'userAgent' => 'private-agent']];
        });
        $factory = $this->createMock(\OCP\Log\ILogFactory::class);
        $factory->method('get')->with('file')->willReturn($writer);
        $privacy = $this->createMock(LogFileNames::class);
        $privacy->expects(self::once())->method('active')->willReturn(true);
        $nativeConfig = $config->mock($this);
        $capability = new AnalyzeEntries(new LogQuery(new LogScanner(new LogReader($factory)), $nativeConfig, $privacy), new LogEnvelope($nativeConfig));
        $result = $capability->analyze([]);
        $summary = $result['structuredContent']['summary'];
        self::assertSame(1, $summary['matched']);
        self::assertSame([['hour' => '2026-10-17T12:00+00:00', 'count' => 1]], $summary['hourly']);
        self::assertSame('[hidden]', $summary['signatures'][0]['example']);
        self::assertArrayNotHasKey('topUserAgents', $summary);
        self::assertStringNotContainsString('synthetic-secret', $result['content'][0]['text']);
        self::assertSame('logs_analyze', $result['structuredContent']['tool']);
    }
}
