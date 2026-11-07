<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Modules\Tables\Presentation\RowsMessages;
use OCA\Mcp\Modules\Tables\Reading\RowsRefusal;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Only the caller's own session passes; another user, no user or an empty caller are refused. */
final class TablesSessionGuardTest extends TestCase {
	/**
	 * The four cases.
	 *
	 * @return void
	 */
	public function testOnlyTheCallersSessionPasses(): void {
		$this->guard('bob')->assertCaller('bob');
		foreach ([['alice', 'bob'], [null, 'bob'], ['', ''], [null, '']] as [$session, $caller]) {
			try {
				$this->guard($session)->assertCaller($caller);
				$this->fail('accepted session ' . var_export($session, true) . ' for ' . var_export($caller, true));
			} catch (RowsRefusal $e) {
				$this->assertSame(RowsMessages::sessionMismatch(), $e->getMessage());
			}
		}
	}

	/**
	 * A guard over a session.
	 *
	 * @param string|null $uid the session user, null for none
	 * @return TablesSessionGuard the guard
	 */
	private function guard(?string $uid): TablesSessionGuard {
		$session = $this->createStub(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);
		return new TablesSessionGuard($session);
	}
}
