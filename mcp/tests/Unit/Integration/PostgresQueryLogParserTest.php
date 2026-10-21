<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Integration;

use OCA\Mcp\Tests\Integration\Support\PostgresQueryLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The CI statement-log reader, against log text in the format harness.sh configures; no server involved. */
final class PostgresQueryLogParserTest extends TestCase {
    private string $path;

    protected function setUp(): void {
        $this->path = tempnam(sys_get_temp_dir(), 'mcp-pglog-');
    }

    protected function tearDown(): void {
        @unlink($this->path);
    }

    public function testReturnsOnlyTheSessionsStatementsBetweenItsMarkers(): void {
        $this->write([
            "2026-10-15 12:00:00.001 UTC mcp-it[10] xid=0 LOG:  statement: SELECT 'mcp-it-marker:aa:begin' AS mcp_it_marker",
            "2026-10-15 12:00:00.002 UTC mcp-it[20] xid=0 LOG:  statement: SELECT 'other session'",
            "2026-10-15 12:00:00.003 UTC mcp-it[10] xid=0 LOG:  execute pdo_stmt_00000001: SELECT a",
            "\tFROM b",
            "2026-10-15 12:00:00.004 UTC mcp-it[10] xid=0 DETAIL:  Parameters: \$1 = 'x'",
            "2026-10-15 12:00:00.005 UTC mcp-it[10] xid=0 ERROR:  relation \"missing\" does not exist at character 15",
            "2026-10-15 12:00:00.006 UTC mcp-it[10] xid=0 STATEMENT:  SELECT * FROM missing",
            "2026-10-15 12:00:00.007 UTC mcp-it[10] xid=0 LOG:  statement: SELECT 'mcp-it-marker:bb:end' AS mcp_it_marker",
        ]);
        $this->assertSame(["SELECT a\nFROM b", 'ERROR: relation "missing" does not exist at character 15'],
            (new PostgresQueryLog($this->path))->statementsBetween(10, 'mcp-it-marker:aa:begin', 'mcp-it-marker:bb:end'));
    }

    public function testAnEmptyWindowRequiresBothMarkers(): void {
        $this->write([
            "2026-10-15 12:00:00.001 UTC mcp-it[10] xid=0 LOG:  statement: SELECT 'mcp-it-marker:aa:begin' AS mcp_it_marker",
            "2026-10-15 12:00:00.002 UTC mcp-it[10] xid=0 LOG:  statement: SELECT 'mcp-it-marker:bb:end' AS mcp_it_marker",
        ]);
        $log = new PostgresQueryLog($this->path);
        $this->assertSame([], $log->statementsBetween(10, 'mcp-it-marker:aa:begin', 'mcp-it-marker:bb:end'));

        $this->expectException(RuntimeException::class);
        $log->statementsBetween(20, 'mcp-it-marker:aa:begin', 'mcp-it-marker:bb:end', 0.2);
    }

    public function testAMissingLogFails(): void {
        $this->expectException(RuntimeException::class);
        (new PostgresQueryLog($this->path . '-absent'))->statementsBetween(10, 'a', 'b', 0.1);
    }

    /** @param list<string> $lines */
    private function write(array $lines): void {
        file_put_contents($this->path, implode("\n", $lines) . "\n");
    }
}
