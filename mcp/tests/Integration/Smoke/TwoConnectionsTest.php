<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Smoke;

use OC\DB\ConnectionAdapter;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Two real sessions to one database (validation T19): each sees the other's committed work and never its
 * uncommitted or rolled-back work. The rows are written straight to the app's own table as test fixtures; the
 * read side is the production mapper on the other session.
 */
final class TwoConnectionsTest extends IntegrationTestCase {
	/** @var list<string> */
	private array $gids = [];

	protected function tearDown(): void {
		parent::tearDown();
		if ($this->gids !== []) {
			$qb = $this->official()->getQueryBuilder();
			$qb->delete('mcp_group_grants')
				->where($qb->expr()->in('group_key', $qb->createNamedParameter(
					array_map(static fn (string $gid): string => hash('sha256', "group\0" . $gid), $this->gids),
					IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
		}
	}

	public function testTheSecondConnectionIsASeparateSessionOnTheSameDatabase(): void {
		$official = $this->official();
		$second = $this->openIndependentConnection();
		$this->assertNotSame($this->sessionId($official), $this->sessionId($second));
		if ($this->cellDatabase() === 'sqlite') {
			// Never two independent :memory: databases: both handles open the instance's database file.
			$this->assertInstanceOf(ConnectionAdapter::class, $official);
			$officialPath = $official->getInner()->getParams()['path'] ?? null;
			$this->assertIsString($officialPath);
			$this->assertNotSame(':memory:', $officialPath);
			$this->assertFileExists($officialPath);
			$this->assertSame($officialPath, $second->getInner()->getParams()['path'] ?? null);
		}
	}

	public function testCommittedWorkBecomesVisibleToTheOtherSessionOnlyAtCommit(): void {
		$official = $this->official();
		$second = $this->openIndependentConnection();
		$gid = $this->newGid();

		$official->beginTransaction();
		$this->seed($official, $gid);
		$this->assertSame([], $this->grantsSeenBy($second, $gid));
		$official->commit();

		$this->assertSame(['_system' => ['eligible']], $this->grantsSeenBy($second, $gid));
	}

	public function testRolledBackWorkIsNeverVisibleToEitherSession(): void {
		$official = $this->official();
		$second = $this->openIndependentConnection();
		$gid = $this->newGid();

		$official->beginTransaction();
		$this->seed($official, $gid);
		$this->assertSame(['_system' => ['eligible']], $this->grantsSeenBy($official, $gid));
		$this->assertSame([], $this->grantsSeenBy($second, $gid));
		$official->rollBack();

		$this->assertSame([], $this->grantsSeenBy($second, $gid));
		$this->assertSame([], $this->grantsSeenBy($official, $gid));
	}

	public function testTheOfficialSessionDoesNotSeeTheSecondSessionsOpenTransaction(): void {
		$official = $this->official();
		$second = $this->openIndependentConnection();
		$gid = $this->newGid();

		$second->beginTransaction();
		$this->seed($second, $gid);
		$this->assertSame([], $this->grantsSeenBy($official, $gid));
		$second->rollBack();

		$this->assertSame([], $this->grantsSeenBy($official, $gid));
	}

	private function newGid(): string {
		$gid = 'mcp-it-two-connections-' . bin2hex(random_bytes(6));
		$this->gids[] = $gid;
		return $gid;
	}

	private function seed(IDBConnection $connection, string $gid): void {
		$qb = $connection->getQueryBuilder();
		$qb->insert('mcp_group_grants')->values([
			'group_key' => $qb->createNamedParameter(hash('sha256', "group\0" . $gid)),
			'group_id' => $qb->createNamedParameter($gid),
			'module' => $qb->createNamedParameter('_system'),
			'operation' => $qb->createNamedParameter('eligible'),
		])->executeStatement();
	}

	/** @return array<string, list<string>> grants of the group as read by the production mapper on that session */
	private function grantsSeenBy(IDBConnection $connection, string $gid): array {
		return (new GroupGrantsMapper($connection))->forGroups([$gid])[0]['grants'];
	}
}
