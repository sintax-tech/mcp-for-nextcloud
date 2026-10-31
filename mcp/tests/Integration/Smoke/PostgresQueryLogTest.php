<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Smoke;

use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCA\Mcp\Tests\Integration\Support\PostgresQueryLog;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\UserAddedEvent;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/**
 * The PostgreSQL statement log captures what each session runs, so a later test can prove that a listener ran no
 * query inside the caller's transaction (contracts §3.3.6, validation T19/F5). Positive controls show the capture
 * works before an empty window is trusted, and a negative control shows a listener query is caught.
 *
 * Harness smoke only: the bracket covers a test closure, not the app's listeners, which share priority 0 with core
 * listeners that run queries. Tickets 06/07 must delimit the real handler (see tests/Integration/README.md).
 */
final class PostgresQueryLogTest extends IntegrationTestCase {
	private PostgresQueryLog $log;
	private ?EventProbe $probe = null;
	private ?string $gid = null;

	protected function setUp(): void {
		if ($this->cellDatabase() !== 'pgsql') {
			$this->markTestSkipped('The statement log is configured only in the PostgreSQL cell');
		}
		// In the PostgreSQL cell a missing log is a harness failure, never a skip.
		$log = PostgresQueryLog::fromEnvironment();
		$this->assertNotNull($log, 'MCP_IT_PG_LOG is not set in the PostgreSQL cell');
		$this->log = $log;
	}

	protected function tearDown(): void {
		$this->probe?->detach();
		parent::tearDown();
		if ($this->gid !== null) {
			Server::get(IGroupManager::class)->get($this->gid)?->delete();
		}
	}

	public function testCapturesEachSessionsStatementsAndTransactionBoundaries(): void {
		$caller = $this->official();
		$other = $this->openIndependentConnection();
		$callerPid = $this->log->backendPid($caller);
		$otherPid = $this->log->backendPid($other);
		$this->assertNotSame($callerPid, $otherPid);

		$before = $this->log->mark($caller, 'before');
		$caller->beginTransaction();
		$begin = $this->log->mark($caller, 'begin');
		$caller->executeQuery('SELECT 1 AS mcp_it_positive_control')->closeCursor();
		$otherBegin = $this->log->mark($other, 'other-begin');
		$other->executeQuery('SELECT 2 AS mcp_it_other_session')->closeCursor();
		$otherEnd = $this->log->mark($other, 'other-end');
		$end = $this->log->mark($caller, 'end');
		$caller->rollBack();
		$after = $this->log->mark($caller, 'after');

		$window = $this->log->statementsBetween($callerPid, $begin, $end);
		$this->assertCount(1, $window);
		$this->assertStringContainsString('mcp_it_positive_control', $window[0]);
		$otherWindow = $this->log->statementsBetween($otherPid, $otherBegin, $otherEnd);
		$this->assertCount(1, $otherWindow);
		$this->assertStringContainsString('mcp_it_other_session', $otherWindow[0]);
		$this->assertMatchesRegularExpression('/^BEGIN/i', implode("\n", $this->log->statementsBetween($callerPid, $before, $begin)));
		$this->assertMatchesRegularExpression('/^ROLLBACK/i', implode("\n", $this->log->statementsBetween($callerPid, $end, $after)));
	}

	public function testAListenerWithoutQueriesLeavesAnEmptyWindowInsideTheCallersTransaction(): void {
		$this->assertSame([], $this->listenerWindow(static function (): void {
		}));
	}

	public function testAListenerQueryInsideTheCallersTransactionIsCaught(): void {
		$window = $this->listenerWindow(function (): void {
			$this->official()->executeQuery('SELECT 3 AS mcp_it_negative_control')->closeCursor();
		});
		$this->assertCount(1, $window);
		$this->assertStringContainsString('mcp_it_negative_control', $window[0]);
	}

	/**
	 * Brackets the listener under test with marker listeners at the adjacent priorities on a real UserAddedEvent
	 * dispatched inside a caller transaction, then returns what the caller's session ran in between. Core and app
	 * listeners keep their own (default 0) priority, outside the bracket: their queries are not the subject's.
	 *
	 * @param callable(): void $subject listener under test
	 * @return list<string> statements of the caller's session while the listener ran
	 */
	private function listenerWindow(callable $subject): array {
		$caller = $this->official();
		$pid = $this->log->backendPid($caller);
		$this->gid = 'mcp-it-pglog-' . bin2hex(random_bytes(6));
		$group = Server::get(IGroupManager::class)->createGroup($this->gid);
		$this->assertInstanceOf(IGroup::class, $group);
		$user = Server::get(IUserManager::class)->get(Fixtures::ALICE);
		$this->assertNotNull($user);

		$markers = [];
		$inTransaction = null;
		$this->probe = new EventProbe(Server::get(IEventDispatcher::class), $caller);
		$this->probe->on(UserAddedEvent::class, function () use (&$markers, $caller): void {
			$markers['begin'] = $this->log->mark($caller, 'listener-begin');
		}, 501);
		$this->probe->on(UserAddedEvent::class, function () use ($subject, $caller, &$inTransaction): void {
			$inTransaction = $caller->inTransaction();
			$subject();
		}, 500);
		$this->probe->on(UserAddedEvent::class, function () use (&$markers, $caller): void {
			$markers['end'] = $this->log->mark($caller, 'listener-end');
		}, 499);

		$caller->beginTransaction();
		$group->addUser($user);
		$caller->rollBack();

		$this->assertTrue($inTransaction);
		$this->assertArrayHasKey('begin', $markers);
		$this->assertArrayHasKey('end', $markers);
		return $this->log->statementsBetween($pid, $markers['begin'], $markers['end']);
	}
}
