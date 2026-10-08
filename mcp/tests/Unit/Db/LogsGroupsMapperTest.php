<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\LogsGroupsMapper;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Service\LogsGroupsConflict;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class LogsGroupsMapperTest extends TestCase {
    public function testFirstReadImportsCanonicalLegacyGroupsOnlyOnce(): void {
        $db = new SqliteDatabase($this, [new Version001002Date20261008000000()]);
        $legacy = new InMemoryAppConfig();
        $legacy->stored['mcp']['logs_groups'] = '["ti","01","1","ti"]';
        $mapper = new LogsGroupsMapper($db->connection(), $legacy->worker($this));
        $this->assertSame(['groups' => ['01', '1', 'ti'], 'version' => 0], $mapper->state());
        $this->assertArrayNotHasKey('logs_groups', $legacy->stored['mcp']);
        $mapper->compareAndSet([], 0);
        $legacy->stored['mcp']['logs_groups'] = '["ti"]';
        $this->assertSame(['groups' => [], 'version' => 1], (new LogsGroupsMapper($db->connection(), $legacy->worker($this)))->state());
        $this->assertCount(1, $db->rows('mcp_logs_groups'));
    }

    public function testDatabaseCasRejectsOldVersionEvenAfterAbaAndStaleCacheRepopulation(): void {
        $db = new SqliteDatabase($this, [new Version001002Date20261008000000()]);
        $legacy = new InMemoryAppConfig();
        $legacy->stored['mcp']['logs_groups'] = '["rh","ti"]';
        $c = $legacy->worker($this);
        $this->assertSame('["rh","ti"]', $c->getValueString('mcp', 'logs_groups'));
        $a = new LogsGroupsMapper($db->connection(), $legacy->worker($this));
        $b = new LogsGroupsMapper($db->connection(), $c);
        $snapshot = $b->state();
        $a->compareAndSet(['ti'], $snapshot['version']);
        // C repopulates the old appconfig snapshot; it has no authority over the new table.
        $legacy->stored['mcp']['logs_groups'] = $c->getValueString('mcp', 'logs_groups');
        try {
            $b->compareAndSet(['rh', 'ti'], $snapshot['version']);
            $this->fail('B must not undo the revocation');
        } catch (LogsGroupsConflict) {
        }
        $this->assertSame(['groups' => ['ti'], 'version' => 1], $a->state());
        $a->compareAndSet(['rh', 'ti'], 1);
        $this->expectException(LogsGroupsConflict::class);
        $b->compareAndSet([], 0); // Same groups as before, different version (ABA).
    }
    public function testConcurrentFirstReadKeepsTheWinningImport(): void {
        $db = new SqliteDatabase($this, [new Version001002Date20261008000000()]);
        $legacy = new InMemoryAppConfig();
        $legacy->stored['mcp']['logs_groups'] = '["ti"]';
        $a = new LogsGroupsMapper($db->connection(), $legacy->worker($this));
        $b = new LogsGroupsMapper($db->connection(), $legacy->worker($this));
        $db->beforeStatement = function () use ($a): void {
            $a->state();
            $a->compareAndSet([], 0);
        };
        $this->assertSame(['groups' => [], 'version' => 1], $b->state());
        $this->assertCount(1, $db->rows('mcp_logs_groups'));
    }

    public function testLegacyCleanupRetriesWithoutBreakingReadsOrReimporting(): void {
        $db = new SqliteDatabase($this, [new Version001002Date20261008000000()]);
        $legacy = $this->createMock(\OCP\IAppConfig::class);
        $present = true;
        $attempts = 0;
        $legacy->expects($this->once())->method('getValueString')->willReturn('["ti"]');
        $legacy->method('hasKey')->willReturnCallback(function () use (&$present): bool { return $present; });
        $legacy->method('deleteKey')->willReturnCallback(function () use (&$attempts, &$present): void {
            if (++$attempts === 1) {
                throw new \RuntimeException('legacy cleanup unavailable');
            }
            $present = false;
        });
        $mapper = new LogsGroupsMapper($db->connection(), $legacy);
        $this->assertSame(['groups' => ['ti'], 'version' => 0], $mapper->state());
        $this->assertSame(1, $attempts);
        $mapper->compareAndSet([], 0);
        $this->assertSame(['groups' => [], 'version' => 1], $mapper->state());
        $this->assertSame(2, $attempts);
        $this->assertFalse($present);
        $this->assertSame(['groups' => [], 'version' => 1], $mapper->state());
        $this->assertSame(2, $attempts, 'no further cleanup after the key is gone');
    }

}
