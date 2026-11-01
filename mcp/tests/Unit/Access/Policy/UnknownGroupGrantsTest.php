<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Tests\Unit\Service\Policy\GroupLifecycleDatabase;

use OCA\Mcp\Db\GroupGrantRows;
use OCA\Mcp\Db\StoredGroupGrant;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\UnknownGroupGrants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real SQLite writer, revision, exact-row deletion, readback and transactional audit coverage. */
final class UnknownGroupGrantsTest extends TestCase {
	use GroupLifecycleDatabase;

	/** @return void Prepare known and unknown rows in the same group. */
	protected function setUp(): void {
		$this->lifecycleDatabase();
		$this->policy('team', grants: [['_system', 'eligible'], ['notes', 'edit'], ['future', 'read'], ['files', 'future.operation']]);
	}

	/** @return UnknownGroupGrants Production service over the test database. */
	private function service(): UnknownGroupGrants {
		return new UnknownGroupGrants(new GroupGrantRows($this->db), $this->state, $this->writer);
	}

	/** @return array<string, array{string,string}> Corrupt or ambiguous pairs, never opaque grants. */
	public static function malformedPairs(): array {
		return ['group deny' => ['_system', 'deny'], 'wrong system operation' => ['_system', 'read'],
			'eligibility on ordinary module' => ['files', 'eligible'], 'empty operation' => ['files', ''],
			'case changed' => ['Files', 'read'], 'space' => ['files', 'future operation'], 'empty module' => ['', 'read'],
			'nul' => ['future', "read\0"], 'invalid utf8' => ['future', "read\xff"], 'ambiguous dots' => ['future', 'a..b'],
			'oversized module' => [str_repeat('a', 33), 'read'], 'oversized operation' => ['files', str_repeat('a', 33)]];
	}

	/** @return void Unknown rows never enter the granting set and reads never mutate storage. */
	public function testUnknownRowsAreOpaqueAndOtherGroupsAndKnownGrantsSurvive(): void {
		$this->policy('other', grants: [['notes', 'delete']]);
		$before = $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_group_grants ORDER BY group_key, module, operation');
		$rows = $this->grants->forGroups(['team', 'other', 'missing']);
		$this->assertSame(['_system' => ['eligible'], 'notes' => ['edit']], $rows[0]['grants']);
		$this->assertCount(2, $rows[0]['unknownGrants']);
		$this->assertSame(['notes' => ['delete']], $rows[1]['grants']);
		$this->assertSame([], $rows[2]['grants']);
		$this->assertSame($before, $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_group_grants ORDER BY group_key, module, operation'));
		$this->assertSame('0', $this->revision());
	}

	/** @return void Normal saves and lifecycle cleanup retain the opaque data for explicit cleanup. */
	public function testOrdinaryReplacementAndLifecyclePurgeDoNotCleanUnknownRows(): void {
		$this->writer->runAdmin('0', [], static fn () => null, function (): bool {
			$this->grants->replaceGroup('team', ['notes' => ['read']]);
			return true;
		});
		$this->assertSame(['files/future.operation', 'future/read', 'notes/read'], $this->rows('team'));
		$this->writer->runAdmin('1', [], static fn () => null, function (): bool {
			$this->grants->deleteGroup('team');
			return true;
		});
		$this->assertSame(['files/future.operation', 'future/read'], $this->rows('team'));
	}

	/** @return void Only the selected unknown row disappears, with an atomic timestamped audit and fresh readback. */
	public function testConfirmedSelectiveCleanupRecordsActorBeforeAfterAndResult(): void {
		$report = $this->service()->report('team');
		$id = $report['unknownGrants'][0]['id'];
		$result = $this->service()->clear('0', 'admin', 'team', [$id], $report['grantSnapshot'], true);
		$this->assertSame(1, $result['removed']);
		$this->assertSame('1', $result['revision']);
		$this->assertCount(1, $result['report']['unknownGrants']);
		$this->assertSame(['_system' => ['eligible'], 'notes' => ['edit']], $this->grants->forGroups(['team'])[0]['grants']);
		$events = $this->database->rows('mcp_policy_events');
		$this->assertCount(1, $events);
		$payload = PolicyEventPayload::validate('audit', json_decode($events[0]['payload'], true));
		$this->assertSame('admin', $payload['actor']);
		$this->assertSame('1', $payload['revision']);
		$this->assertSame($this->now, (int)$events[0]['created_at']);
		$this->assertSame('delivered', $events[0]['status']);
		$change = $payload['changes'][0];
		$this->assertSame('team', $change['gid']);
		$removed = array_values(array_filter($report['unknownGrants'], static fn (array $row): bool => $row['id'] === $id))[0];
		$this->assertSame(['unknownCleanup' => [['module' => $removed['module'], 'operation' => $removed['operation']]]], json_decode($change['before'], true));
		$this->assertSame(['unknownCleanup' => []], json_decode($change['after'], true));
		$this->assertStringNotContainsString($id, $change['before']);
		$this->assertStringNotContainsString('grantSnapshot', $change['before']);
		$this->assertSame($events[0]['id'], $result['auditId']);
	}

	/** @return array<string,array{mixed}> Confirmation must be the boolean true. */
	public static function confirmations(): array {
		return ['absent' => [null], 'false' => [false], 'string' => ['true'], 'integer' => [1]];
	}

	/** @param mixed $confirm Invalid confirmation. @return void No mutation without explicit confirmation. */
	#[DataProvider('confirmations')]
	public function testConfirmationIsStrict(mixed $confirm): void {
		$report = $this->service()->report('team');
		try {
			$this->service()->clear('0', 'admin', 'team', [$report['unknownGrants'][0]['id']], $report['grantSnapshot'], $confirm);
			$this->fail('Missing strict confirmation accepted');
		} catch (\InvalidArgumentException) {
			$this->assertSame('0', $this->revision());
			$this->assertCount(4, $this->rows('team'));
			$this->assertSame([], $this->database->rows('mcp_policy_events'));
		}
	}

	/** @return void A stale revision, changed snapshot or a selected known row cannot be deleted. */
	public function testConcurrencyAndKnownReintroducedSelectionFailClosed(): void {
		$report = $this->service()->report('team');
		$unknown = $report['unknownGrants'][0]['id'];
		$known = StoredGroupGrant::id('team', 'notes', 'edit');
		foreach ([['1', $unknown, $report['grantSnapshot']], ['0', $unknown, str_repeat('a', 64)], ['0', $known, $report['grantSnapshot']]] as [$rev, $id, $snapshot]) {
			try {
				$this->service()->clear($rev, 'admin', 'team', [$id], $snapshot, true);
				$this->fail('Stale or known selection accepted');
			} catch (PolicyConflict) {
				$this->assertSame('0', $this->revision());
				$this->assertCount(4, $this->rows('team'));
			}
		}
		$this->database->dbal->insert('mcp_group_grants', ['group_key' => hash('sha256', "group\0team"),
			'group_id' => 'team', 'module' => 'future', 'operation' => 'write']);
		$this->expectException(PolicyConflict::class);
		$this->service()->clear('0', 'admin', 'team', [$unknown], $report['grantSnapshot'], true);
	}

	/** @return void An audit insert failure rolls back rows, revision and mutex together. */
	public function testAuditFailureRollsBackCleanupAndRevision(): void {
		$report = $this->service()->report('team');
		$this->database->dbal->executeStatement("CREATE TRIGGER reject_audit BEFORE INSERT ON mcp_policy_events BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
		try {
			$this->service()->clear('0', 'admin', 'team', [$report['unknownGrants'][0]['id']], $report['grantSnapshot'], true);
			$this->fail('Audit failure must roll back');
		} catch (PolicyUnavailable) {
			$this->assertSame('0', $this->revision());
			$this->assertCount(4, $this->rows('team'));
			$this->assertSame([], $this->database->rows('mcp_policy_events'));
			$this->assertFalse($this->db->inTransaction());
		}
	}

	/** @return void Diagnosis refuses corruption without depending on resolver availability. */
	public function testMalformedRowsStillBlockAndCannotBeCleanedAsUnknown(): void {
		$this->database->dbal->insert('mcp_group_grants', ['group_key' => hash('sha256', "group\0team"),
			'group_id' => 'team', 'module' => 'Files', 'operation' => 'read']);
		$this->expectException(PolicyUnavailable::class);
		$this->service()->report('team');
	}
	/** @return void An undeliverable log is retained transactionally for the existing event processor. */
	public function testAuditDeliveryFailureLeavesCommittedPendingRecord(): void {
		$report = $this->service()->report('team');
		$this->database->dbal->executeStatement("CREATE TRIGGER reject_delivery BEFORE UPDATE ON mcp_policy_events BEGIN SELECT RAISE(ABORT, 'log unavailable'); END");
		$result = $this->service()->clear('0', 'admin', 'team', [$report['unknownGrants'][0]['id']], $report['grantSnapshot'], true);
		$this->assertTrue($result['auditPending']);
		$this->assertSame('1', $this->revision());
		$this->assertSame('pending', $this->database->rows('mcp_policy_events')[0]['status']);
		$this->assertCount(3, $this->rows('team'));
	}

	/** @return void Rows changed after admission are re-read under the production writer mutex. */
	public function testMutationBetweenAdmissionAndMutexRefusesCleanup(): void {
		$report = $this->service()->report('team');
		$bridge = $this->createMock(\OCA\Mcp\Access\Policy\PolicyWriter::class);
		$bridge->method('runAdmin')->willReturnCallback(function ($expected, $uids, $prepare, $apply): array {
			return $this->writer->runAdmin($expected, $uids, function (): void {
				$this->database->dbal->executeStatement("UPDATE mcp_group_grants SET operation = 'write' WHERE module = 'future'");
			}, $apply);
		});
		$service = new UnknownGroupGrants(new GroupGrantRows($this->db), $this->state, $bridge);
		try {
			$service->clear('0', 'admin', 'team', [$report['unknownGrants'][0]['id']], $report['grantSnapshot'], true);
			$this->fail('Changed rows accepted after admission');
		} catch (PolicyConflict) {
			$this->assertSame('0', $this->revision());
			$this->assertCount(4, $this->rows('team'));
			$this->assertSame([], $this->database->rows('mcp_policy_events'));
		}
	}

	/** @return void An opaque ID from another group cannot delete either group's rows. */
	public function testCleanupRefusesIdFromAnotherGroupWithoutWriting(): void {
		$this->policy('other', grants: [['future', 'read']]);
		$report = $this->service()->report('team');
		$other = $this->service()->report('other');
		$before = $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_group_grants ORDER BY group_key, module, operation');
		try {
			$this->service()->clear('0', 'admin', 'team', [$other['unknownGrants'][0]['id']], $report['grantSnapshot'], true);
			$this->fail('An ID from another group was accepted');
		} catch (PolicyConflict) {
			$this->assertSame($before, $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_group_grants ORDER BY group_key, module, operation'));
			$this->assertSame('0', $this->revision());
			$this->assertSame([], $this->database->rows('mcp_policy_events'));
			$this->assertFalse($this->db->inTransaction());
		}
	}

}
