<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Logs\Reading;

use OCA\Mcp\Modules\Logs\Reading\{LogQuery, LogReader, LogScanner};
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Tests\Support\Logs\FakeFileLog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class LogQueryTest extends TestCase {
    public function testValidationPrecedesOneBoundedRead(): void {
        self::assertTrue(class_exists(LogQuery::class), 'The bounded query capability is missing');
        $log = new FakeFileLog(FakeFileLog::lines(10, static fn (): array => []));
        $factory = $this->createMock(\OCP\Log\ILogFactory::class);
        $factory->method('get')->with('file')->willReturn($log);
        $privacy = $this->createMock(LogFileNames::class);
        $hidden = true;
        $privacy->method('active')->willReturnCallback(static fn (): bool => $hidden);
        $query = new LogQuery(new LogScanner(new LogReader($factory)), (new InMemoryConfig())->mock($this), $privacy);
        foreach ([['contains' => 'secret', 'limit' => 0], ['limit' => 0], ['offset' => 20001]] as $args) {
            try { $query->read($args, true); self::fail('Expected query refusal'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        self::assertSame([], $log->calls);
        $window = $query->read(['offset' => 19999], true);
        self::assertSame([[1, 19999]], $log->calls);
        self::assertTrue($window->messagesHidden);
        self::assertSame([], $window->entries());
        self::assertSame(['filters', 'scan'], array_keys($window->payload()));
        self::assertSame(20000, $window->payload()['scan']['readBudget']);
        $query->read(['offset' => 20000], false);
        self::assertCount(1, $log->calls, 'An exhausted budget cannot trigger a second native read');
    }
}
