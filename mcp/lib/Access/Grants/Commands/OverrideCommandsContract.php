<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** Public OverrideCommands contract inherited by the historical policy facade. */
interface OverrideCommandsContract {
	/**
	 * Removes an individual override through the same transaction; absence remains absence.
	 *
	 * @param string $uid Nextcloud user id
	 * @param string $module catalog module
	 * @param string $operation catalog operation of that module
	 * @throws \InvalidArgumentException for an unknown user, module or operation
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function resetOverride(string $uid, string $module, string $operation): void;
}
