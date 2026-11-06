<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\GroupGrantRows;
use OCA\Mcp\Db\StoredGroupGrant;
use OCA\Mcp\Access\Grants\GrantCatalog;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Canonical DTO and ambiguous-storage defenses independent of the database primary key. */
final class StoredGroupGrantTest extends TestCase {
	/** @return void Catalog entries including disabled optional apps remain known, never orphaned. */
	public function testCurrentCatalogAndSystemEligibilityRemainRecognized(): void {
		foreach (GrantCatalog::CATALOG + ['_system' => ['eligible']] as $module => $operations) {
			foreach ($operations as $operation) {
				$grant = new StoredGroupGrant('team', $module, $operation);
				$this->assertTrue($grant->recognized);
				$this->assertSame(StoredGroupGrant::id('team', $module, $operation), $grant->selectionId);
			}
		}
		$this->assertFalse((new StoredGroupGrant('team', 'future', 'read'))->recognized);
		$this->assertFalse((new StoredGroupGrant('team', 'files', 'future.operation'))->recognized);
	}

	/** @return void Duplicate opaque rows are ambiguous even when the database fails to enforce uniqueness. */
	public function testDuplicateUnknownRowBlocksWholeReadAndClosesCursor(): void {
		$row = ['group_key' => hash('sha256', "group\0team"), 'group_id' => 'team', 'module' => 'future', 'operation' => 'read'];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls($row, $row, false);
		$result->expects($this->once())->method('closeCursor')->willReturn(true);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('in')->willReturn('group_key IN (:key)');
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$this->expectException(\UnexpectedValueException::class);
		(new GroupGrantRows($db))->read(['team']);
	}
}
