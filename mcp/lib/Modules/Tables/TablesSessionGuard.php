<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables;

use OCA\Mcp\Modules\Tables\Reading\RowsRefusal;

use OCP\IUserSession;

/**
 * Refuses to read rows unless the user of the session is the authenticated caller. In every supported Tables release
 * the user/group cell formatter takes the user for a team's name from IUserSession at formatting time
 * (RowCellUsergroupMapper → CircleHelper::getCircleDisplayName(), which also starts a Circles session for it), not from
 * the id handed to Row2Mapper::findAll(). The MCP endpoint makes the caller the session user before any tool runs; any
 * difference found anyway (a later change of the session, another entry point, a background call) is refused here,
 * before the engine, without switching any session or overriding anything of Tables.
 */
class TablesSessionGuard {
	/**
	 * Receives the session.
	 *
	 * @param IUserSession $session the user session of the request
	 * @return void
	 */
	public function __construct(
		private IUserSession $session,
	) {
	}

	/**
	 * Checks that the session belongs to the caller.
	 *
	 * @param string $userId authenticated user UID
	 * @return void
	 * @throws RowsRefusal when the caller is empty or the session belongs to someone else, or to nobody
	 */
	public function assertCaller(string $userId): void {
		if ($userId === '' || $this->session->getUser()?->getUID() !== $userId) {
			throw RowsRefusal::sessionMismatch();
		}
	}
}
