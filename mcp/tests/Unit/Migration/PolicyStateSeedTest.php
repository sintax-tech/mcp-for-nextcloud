<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Migration;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Migration\PolicyStateSeedStep;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The policy state row exists after a fresh install and after an upgrade, and neither path replaces a committed
 * revision.
 *
 * A fresh install is the case the migration alone cannot cover: the server asks a migration for schema only when it
 * installs an app on an empty instance, so postSchemaChange never runs there. The row comes from the step declared in
 * appinfo/info.xml instead, which the server runs on install and after a migration.
 */
final class PolicyStateSeedTest extends TestCase {
	private const MANIFEST = __DIR__ . '/../../../appinfo/info.xml';

	private SqliteDatabase $database;
	private IDBConnection $db;

	protected function setUp(): void {
		$this->database = new SqliteDatabase($this, [new Version001100Date20261015000000($this->createMock(IDBConnection::class))]);
		$this->db = $this->database->connection();
	}

	/** The seed the migration runs on an upgrade. */
	private function seedFromMigration(): void {
		(new Version001100Date20261015000000($this->db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
	}

	/** The step the server runs on install and after a migration, as appinfo/info.xml declares it. */
	private function declaredStep(string $phase): PolicyStateSeedStep {
		$steps = simplexml_load_file(self::MANIFEST)->{'repair-steps'}->{$phase}->step ?? [];
		$this->assertCount(1, $steps, "appinfo/info.xml must declare exactly one <$phase> step");
		$class = (string)$steps[0];
		$this->assertSame(PolicyStateSeedStep::class, $class);
		return new $class(new PolicyStateSeeder($this->db));
	}

	public function testAFreshInstallLeavesTheRowTheMigrationNeverGetsToSeed(): void {
		// Only changeSchema ran, as the server does for an app installed on an empty instance.
		$this->assertSame([], $this->database->rows('mcp_policy_state'));

		$this->declaredStep('install')->run($this->createMock(IOutput::class));

		$this->assertSame([['id' => 1, 'revision' => 0, 'lock_seq' => 0, 'auth_epoch' => 0]], $this->database->rows('mcp_policy_state'));
		$this->assertSame(['revision' => '0', 'lock_seq' => '0', 'auth_epoch' => '0'], (new PolicyStateMapper($this->db))->state());
	}

	public function testEverySeedPathLeavesOneRowAndKeepsACommittedRevision(): void {
		$this->seedFromMigration();
		$state = new PolicyStateMapper($this->db);
		$state->acquire();
		$state->compareAndSet('0');

		$this->declaredStep('install')->run($this->createMock(IOutput::class));
		$this->declaredStep('post-migration')->run($this->createMock(IOutput::class));
		$this->seedFromMigration();

		$this->assertSame(['revision' => '1', 'lock_seq' => '1', 'auth_epoch' => '0'], $state->state());
		$this->assertSame([['id' => 1, 'revision' => 1, 'lock_seq' => 1, 'auth_epoch' => 0]], $this->database->rows('mcp_policy_state'));
	}

	public function testASeedRacingAnotherInitialisationKeepsTheRowTheOtherCommitted(): void {
		// The other initialisation inserts and commits a write between this seed's build and its insert.
		$this->database->beforeStatement = function (): void {
			(new PolicyStateSeeder($this->db))->seed();
			$this->database->dbal->update('mcp_policy_state', ['revision' => 7], ['id' => 1]);
		};

		(new PolicyStateSeeder($this->db))->seed();

		$this->assertNull($this->database->beforeStatement, 'the competing seed must have run first');
		$this->assertSame([['id' => 1, 'revision' => 7, 'lock_seq' => 0, 'auth_epoch' => 0]], $this->database->rows('mcp_policy_state'));
	}

	public function testOnlyTheUniqueViolationIsTreatedAsAlreadySeeded(): void {
		$error = new class('missing table') extends Exception {
			public function getReason(): ?int { return self::REASON_DATABASE_OBJECT_NOT_FOUND; }
		};
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['insert', 'setValue'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('executeStatement')->willThrowException($error);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->expectExceptionObject($error);
		(new PolicyStateSeeder($db))->seed();
	}
}
