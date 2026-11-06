<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Contracts\PollWriteStale;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use PHPUnit\Framework\Attributes\{DataProvider, RunTestsInSeparateProcesses, PreserveGlobalState};
use PHPUnit\Framework\TestCase;

/** Exercises races and identity cleanup through the actual adapter and snapshot reader. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PollsMutationAdapterGuardsTest extends TestCase {
	/** @return void */
	public function testFinalSnapshotRaceStopsBeforeAttemptAndToggle(): void {
		$rig = new PollsMutationGuardRig($this);
		$r = ['tool' => 'polls_set_poll_state', 'poll_id' => 1, 'state' => 'archived'];
		$rig->adapter->open('alice', 'configure');
		$before = $rig->adapter->snapshot('alice', $r);
		$rig->poll = new class extends \OCA\Polls\Db\Poll {
			/** @return int Concurrently archived row. */
			public function getDeleted(): int {
				return 1;
			}
		};
		$attempts = 0;
		try {
			$rig->adapter->mutate('alice', $r, $before, static function () use (&$attempts): void {
				$attempts++;
			});
			self::fail('Concurrent archive reached the sink');
		} catch (PollWriteStale) {
			self::assertSame(0, $attempts);
			self::assertSame(0, $rig->sinks);
		} finally {
			$rig->adapter->close();
		}
	}
	/** @return iterable<string,array{string}> */
	public static function revocations(): iterable {
		yield 'native identity' => ['logged'];
		yield 'resource scope' => ['scope'];
	}
	/**
	 * @param string $flag Guard revoked by the mapper read.
	 * @return void
	 */
	#[DataProvider('revocations')]
	public function testRechecksIdentityAndScopeAfterReading(string $flag): void {
		$rig = new PollsMutationGuardRig($this);
		$rig->adapter->open('alice', 'configure');
		$rig->onRead = static function () use ($rig, $flag): void {
			$rig->$flag = false;
		};
		$this->expectException(ToolFailure::class);
		try {
			$rig->adapter->snapshot('alice', ['tool' => 'polls_set_poll_state', 'poll_id' => 1, 'state' => 'archived']);
		} finally {
			self::assertSame(0, $rig->sinks);
			$rig->adapter->close();
		}
	}
	/** @return void */
	public function testCloseCleansNativeIdentityAndDropsContext(): void {
		$rig = new PollsMutationGuardRig($this);
		$rig->adapter->open('alice', 'configure');
		$count = $rig->native->cleans;
		$rig->adapter->close();
		self::assertSame($count + 1, $rig->native->cleans);
		$rig->adapter->close();
		self::assertSame($count + 1, $rig->native->cleans);
		$this->expectException(PollsRefusal::class);
		$rig->adapter->snapshot('alice', ['tool' => 'polls_set_poll_state', 'poll_id' => 1]);
	}
	/** @return void */
	public function testForeignOptionRowIsRejectedBeforeProjection(): void {
		$rig = new PollsMutationGuardRig($this);
		$rig->children = [new class {
			/** @return int Foreign parent. */
			public function getPollId(): int {
				return 99;
			}
		}];
		$this->expectException(PollsRefusal::class);
		$rig->snapshots->read($rig->native, 'alice', ['tool' => 'polls_remove_option', 'poll_id' => 1, 'option_id' => 10], '9.3.0');
	}
	/** @return void */
	public function testPollGroupSharesNeverJoinTheTargetRows(): void {
		$rig = new PollsMutationGuardRig($this);
		$rig->children = [new \OCA\Polls\Db\Share(), new class extends \OCA\Polls\Db\Share {
			/** @return int Poll-group container share. */
			public function getGroupId(): int {
				return 5;
			}
		}];
		self::assertCount(1, $rig->snapshots->shares($rig->native, 1));
	}
	/** @return void */
	public function testPrivateShareLabelIsNotProjected(): void {
		$rig = new PollsMutationGuardRig($this);
		$share = new class extends \OCA\Polls\Db\Share {
			/** @return string Individual native share. */
			public function getType(): string {
				return 'user';
			}
		};
		self::assertSame('', \OCA\Mcp\Modules\Polls\Integration\PollsWriteProjection::share($share)['label']);
	}
}
