<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Discovery;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tools\Tables\Discovery\TablesDiscovery;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * The tables of a user come from TableService::findAll() without the tutorial table and without its session-bound
 * enrichment, each authorized again for the user before it is kept, in id order, with whether the user manages it.
 */
final class TablesDiscoveryTest extends TestCase {
	/**
	 * findAll() is always called for the user, skipping the enrichment and never creating the tutorial table, and a
	 * user without tables gets none and no write.
	 *
	 * @return void
	 */
	public function testFindAllNeverCreatesTheTutorialTable(): void {
		$app = TablesScene::app();
		foreach (['alice', 'dave'] as $uid) {
			(new TablesDiscovery($app))->tables($uid);
		}
		$this->assertSame([['alice', true, false, false], ['dave', true, false, false]], $app->callsOf('TableService', 'findAll'));
		$this->assertSame([], (new TablesDiscovery($app))->tables('dave'));
		$this->assertSame([], $app->writes);
	}

	/**
	 * Owner, read-only share and manage share: each user gets the tables they can open, in id order, with whether they
	 * manage them.
	 *
	 * @return void
	 */
	public function testEachUserGetsTheTablesTheyCanOpen(): void {
		$app = TablesScene::app();
		$found = static fn (string $uid): array => array_map(static fn (array $e): array => [$e['table']->getId(), $e['manages']], (new TablesDiscovery($app))->tables($uid));
		$this->assertSame([[1, true], [3, true]], $found('alice'));
		$this->assertSame([[1, false]], $found('carol'));
		$this->assertSame([[1, true]], $found('frank'));
		$this->assertSame([], $found('bob'), 'a shared view never brings its table');
		$this->assertSame([], $found('erin'), 'a view of an application never brings its table');
	}

	/**
	 * A table findAll() returns but the permissions refuse is dropped before anything else of it is read.
	 *
	 * @return void
	 */
	public function testATableThePermissionsRefuseIsDropped(): void {
		$app = TablesScene::app()->withStaleTable(3, 'carol');
		$this->assertSame([1], array_map(static fn (array $e): int => $e['table']->getId(), (new TablesDiscovery($app))->tables('carol')));
		$this->assertSame([[3, 'carol'], [1, 'carol']], $app->callsOf('PermissionsService', 'canReadColumnsByTableId'));
		$this->assertCount(1, $app->callsOf('PermissionsService', 'canManageTable'), 'manage is asked only for the kept table');
		$this->assertSame([], array_values(array_filter($app->callsOf('Table#3', 'getTitle'))), 'the refused table is never read');
	}

	/**
	 * Tables off for the user: the failure of the module, and no call into the app.
	 *
	 * @return void
	 */
	public function testTablesOffFailsBeforeAnyCall(): void {
		$app = TablesScene::app();
		$app->enabled = false;
		try {
			(new TablesDiscovery($app))->tables('alice');
			$this->fail('expected the Tables app to be unavailable');
		} catch (TablesUnavailableException) {
		}
		$this->assertSame([], $app->journal->getArrayCopy());
	}
}
