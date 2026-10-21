<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Logs\Listing;

use OCA\Mcp\Modules\Logs\Listing\ListEntries;
use OCA\Mcp\Modules\Logs\Reading\{LogQuery, LogReader, LogScanner};
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Tests\Support\Logs\FakeFileLog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class ListEntriesTest extends TestCase {
    public function testListUsesTheWindowPrivacyState(): void {
        self::assertTrue(class_exists(ListEntries::class), 'The listing capability is missing');
        $log = new FakeFileLog(FakeFileLog::lines(2, static fn (): array => ['message' => 'synthetic-secret', 'remoteAddr' => '203.0.113.7']));
        $factory = $this->createMock(\OCP\Log\ILogFactory::class);
        $factory->method('get')->with('file')->willReturn($log);
        $privacy = $this->createMock(LogFileNames::class);
        $privacy->expects(self::exactly(2))->method('active')->willReturnOnConsecutiveCalls(true, false);
        $config = (new InMemoryConfig())->mock($this);
        $capability = new ListEntries(new LogQuery(new LogScanner(new LogReader($factory)), $config, $privacy), new LogEnvelope($config));
        foreach ([true, false] as $hidden) {
            $result = $capability->list(['limit' => 1]);
            $data = $result['structuredContent'];
            self::assertSame(1, $data['count']);
            self::assertSame($hidden, $data['source']['messagesHidden']);
            self::assertSame($hidden ? '[hidden]' : 'synthetic-secret', $data['entries'][0]['message']);
            self::assertSame('203.0.x.x', $data['entries'][0]['remoteAddr']);
            self::assertSame('logs_list', $data['tool']);
            self::assertSame($data, json_decode(substr($result['content'][0]['text'], strlen(LogEnvelope::NOTICE) + 2), true));
        }
        self::assertSame([[1, 0], [1, 0]], $log->calls);
    }
}
