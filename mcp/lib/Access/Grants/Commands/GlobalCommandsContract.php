<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** Public GlobalCommands contract inherited by the historical policy facade. */
interface GlobalCommandsContract {
	/**
	 * Turns the service switch on or off. Every explicit disable moves the global auth epoch, also when the stored
	 * value was already off (set outside the app, for instance): every credential issued so far stays invalid, even
	 * after the service is switched on again. Nothing valid can exist while the service is off, so this is safe.
	 *
	 * @param bool $enabled new state of the global service switch
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function setGlobalEnabled(bool $enabled): void;

	/**
	 * The service switch from the admin page: same effect as setGlobalEnabled(), behind the expected revision, with
	 * an audit event when it changes.
	 *
	 * @param string $expectedRevision the revision the administrator's page was built from
	 * @param string $actor uid of the administrator
	 * @param bool $enabled new state of the global service switch
	 * @return array{revision:string, changed:bool}
	 * @throws \InvalidArgumentException for an invalid revision
	 * @throws PolicyConflict when the expected revision is stale
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function adminSetGlobalEnabled(string $expectedRevision, string $actor, bool $enabled): array;
}
